<?php

namespace App\Http\Controllers\API;

use App\Helpers\CommonHelper;
use App\Helpers\ProductHelper;
use App\Http\Controllers\Controller;
use App\Services\LanguageService;
use App\Jobs\ProcessReferralBonusAfterReturnPeriod;
use App\Jobs\SendEmailJob;
use App\Models\DeliveryBoy;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\OrderStatusList;
use App\Models\Role;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Models\DeliveryBoyCashCollection;
use App\Models\DeliveryBoySettlement;
use App\Models\PromoCode;
use App\Models\ReturnRequest;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class OrdersApiController extends Controller
{
    public function getOrders(Request $request)
    {
        $limit = $request->input('per_page', 10);
        $offset = (($request->input('page', 0)) - 1) * $limit;
        $search = $request->input('search', '');

        $sellers = $this->getStoresWithTranslations();

        $storeId = (isset($request->seller) && $request->seller != '') ? (int) $request->seller : null;

        $sellerNameSubquery = $storeId
            ? DB::raw("(SELECT s.name FROM stores s INNER JOIN order_items oi ON s.id = oi.store_id WHERE oi.order_id = orders.id AND oi.store_id = {$storeId} LIMIT 1) as store_name")
            : DB::raw('(SELECT s.name FROM stores s INNER JOIN order_items oi ON s.id = oi.store_id WHERE oi.order_id = orders.id LIMIT 1) as store_name');

        $ordersQuery = Order::select(
            'orders.id',
            'orders.order_number',
            'orders.invoice_number',
            'orders.mobile',
            'orders.total',
            'orders.delivery_charge',
            'orders.wallet_balance',
            'orders.final_total',
            'orders.remaining_final',
            'orders.payment_method',
            'orders.additional_charges',
            'orders.surge_charges',
            'orders.promo_discount',
            'orders.active_status',
            'orders.channel',
            'orders.address',
            'orders.delivery_boy_id',
            'orders.created_at',
            'orders.user_id',
            'orders.currency',
            // Task #9: Include GST summary in order list
            'orders.cgst_amount',
            'orders.sgst_amount',
            'orders.igst_amount',
            'orders.is_intra_state',
            'orders.company_state',
            'orders.customer_state',
            'users.name as user_name',
            // Latest transaction status (paid/failed) — null when none recorded.
            DB::raw('(SELECT t.status FROM transactions t WHERE t.order_id = orders.id ORDER BY t.id DESC LIMIT 1) as payment_status'),
            // Comma-joined item names for the list "items" column.
            DB::raw('(SELECT GROUP_CONCAT(oi.product_name SEPARATOR ", ") FROM order_items oi WHERE oi.order_id = orders.id) as items_preview'),
            // Delivery city/state from the order address.
            DB::raw('(SELECT CONCAT_WS(", ", ua.city, ua.state) FROM user_addresses ua WHERE ua.id = orders.address_id LIMIT 1) as customer_city'),
            // Assigned delivery boy name (null = unassigned).
            DB::raw('(SELECT db.name FROM delivery_boys db WHERE db.id = orders.delivery_boy_id LIMIT 1) as delivery_boy_name'),
            $sellerNameSubquery
        )
            ->leftJoin('users', 'orders.user_id', '=', 'users.id');

        // Only parse dates when actually provided
        if (isset($request->startDate) && $request->startDate != "" && isset($request->endDate) && $request->endDate != "") {
            $startDate = Carbon::parse($request->startDate)->startOfDay();
            $endDate = Carbon::parse($request->endDate)->endOfDay();

            // Use whereExists for better performance than join
            $ordersQuery = $ordersQuery->whereExists(function ($query) use ($startDate, $endDate) {
                $query->select(DB::raw(1))
                    ->from('order_items')
                    ->whereColumn('order_items.order_id', 'orders.id')
                    ->whereBetween('order_items.created_at', [$startDate, $endDate]);
            });
        }

        if (isset($request->seller) && $request->seller != "") {
            // Use whereExists for seller filter to avoid join issues
            $ordersQuery = $ordersQuery->whereExists(function ($query) use ($request) {
                $query->select(DB::raw(1))
                    ->from('order_items')
                    ->whereColumn('order_items.order_id', 'orders.id')
                    ->where('order_items.store_id', $request->seller);
            });
        }

        // Mode / channel filter (quick | ecommerce).
        if (isset($request->channel) && $request->channel != "" && in_array($request->channel, ['quick', 'ecommerce'], true)) {
            $ordersQuery = $ordersQuery->where('orders.channel', $request->channel);
        }

        // Country / zone filters (admin panel dropdowns).
        if (!empty($request->country_id)) {
            $ordersQuery = $ordersQuery->where('orders.country_id', $request->country_id);
        }
        if (!empty($request->zone_id)) {
            $ordersQuery = $ordersQuery->where('orders.zone_id', $request->zone_id);
        }

        // Payment method filter.
        if (isset($request->payment_method) && $request->payment_method != "") {
            $ordersQuery = $ordersQuery->where('orders.payment_method', $request->payment_method);
        }

        // Payment status filter (latest transaction status, or 'pending' when none/unpaid).
        if (isset($request->payment_status) && $request->payment_status != "") {
            $ps = $request->payment_status;
            $ordersQuery = $ordersQuery->where(function ($q) use ($ps) {
                $latest = '(SELECT t.status FROM transactions t WHERE t.order_id = orders.id ORDER BY t.id DESC LIMIT 1)';
                if ($ps === 'pending') {
                    $q->whereRaw("COALESCE($latest, 'pending') = 'pending'");
                } else {
                    $q->whereRaw("$latest = ?", [$ps]);
                }
            });
        }

        // Amount range (payable total).
        if (isset($request->amount_min) && $request->amount_min !== "" && is_numeric($request->amount_min)) {
            $ordersQuery = $ordersQuery->where('orders.final_total', '>=', (float) $request->amount_min);
        }
        if (isset($request->amount_max) && $request->amount_max !== "" && is_numeric($request->amount_max)) {
            $ordersQuery = $ordersQuery->where('orders.final_total', '<=', (float) $request->amount_max);
        }

        // Product name filter.
        if (isset($request->product_name) && $request->product_name != "") {
            $pname = $request->product_name;
            $ordersQuery = $ordersQuery->whereExists(function ($query) use ($pname) {
                $query->select(DB::raw(1))
                    ->from('order_items')
                    ->whereColumn('order_items.order_id', 'orders.id')
                    ->where('order_items.product_name', 'like', "%{$pname}%");
            });
        }

        if ($search) {
            // Optimize search with better query structure
            $ordersQuery = $ordersQuery->where(function ($query) use ($search) {
                $query->where('orders.payment_method', 'like', "%{$search}%")
                    ->orWhere('orders.id', 'like', "%{$search}%")
                    ->orWhere('orders.order_number', 'like', "%{$search}%")
                    ->orWhere('orders.invoice_number', 'like', "%{$search}%")
                    ->orWhere('orders.mobile', 'like', "%{$search}%")
                    ->orWhere('orders.delivery_charge', 'like', "%{$search}%")
                    ->orWhere('orders.wallet_balance', 'like', "%{$search}%")
                    ->orWhere('orders.remaining_final', 'like', "%{$search}%")
                    ->orWhere('orders.total', 'like', "%{$search}%")
                    ->orWhereExists(function ($subQuery) use ($search) {
                        $subQuery->select(DB::raw(1))
                            ->from('users')
                            ->whereColumn('users.id', 'orders.user_id')
                            ->where('users.name', 'like', "%{$search}%");
                    })
                    ->orWhereExists(function ($subQuery) use ($search) {
                        $subQuery->select(DB::raw(1))
                            ->from('order_items')
                            ->leftJoin('stores', 'order_items.store_id', '=', 'stores.id')
                            ->whereColumn('order_items.order_id', 'orders.id')
                            ->where('stores.name', 'like', "%{$search}%");
                    })
                    ->orWhereExists(function ($subQuery) use ($search) {
                        $subQuery->select(DB::raw(1))
                            ->from('order_items')
                            ->whereColumn('order_items.order_id', 'orders.id')
                            ->where('order_items.active_status', 'like', "%{$search}%");
                    });
            });
        }

        // Stat-card counts — computed over all current filters EXCEPT status, so
        // the cards reflect the filtered context but still show each bucket.
        $countsRaw = (clone $ordersQuery)
            ->select('orders.active_status', DB::raw('COUNT(*) as c'))
            ->groupBy('orders.active_status')
            ->pluck('c', 'orders.active_status');
        $bucket = fn(array $ids) => (int) collect($ids)->sum(fn($id) => (int) ($countsRaw[$id] ?? 0));
        $counts = [
            'total'      => (int) $countsRaw->sum(),
            'pending'    => $bucket([1, 2]),          // payment pending + received
            'processing' => $bucket([3, 4, 5, 9, 10, 11]), // processed/shipped/out-for-delivery + preparing/ready-for-pickup/picked-up
            'delivered'  => $bucket([6]),
            'cancelled'  => $bucket([7, 8]),          // cancelled + returned
        ];

        // Status filter (applied after counts so cards stay representative).
        if (isset($request->status) && $request->status != "") {
            $ordersQuery = $ordersQuery->where('orders.active_status', $request->status);
        }

        // Get total count using a separate optimized query (without groupBy)
        $orders_total = (clone $ordersQuery)->count();

        // Apply ordering and pagination (sort: newest|oldest|amount_high|amount_low).
        $sort = strtolower((string) $request->input('sort', 'newest'));
        switch ($sort) {
            case 'oldest':
                $ordersQuery->orderBy('orders.id', 'ASC');
                break;
            case 'amount_high':
                $ordersQuery->orderBy('orders.final_total', 'DESC');
                break;
            case 'amount_low':
                $ordersQuery->orderBy('orders.final_total', 'ASC');
                break;
            default: // newest
                $ordersQuery->orderBy('orders.id', 'DESC');
                break;
        }
        $orders = $ordersQuery->skip($offset)->take($limit)->get();

        // Normalize additional_charges to an array. It is array-cast on the Order
        // model now, but tolerate legacy JSON strings too.
        $orders->transform(function ($order) {
            $ac = $order->additional_charges;
            if (is_string($ac)) {
                $decoded = json_decode($ac, true);
                $order->additional_charges = is_array($decoded) ? $decoded : [];
            } elseif (!is_array($ac)) {
                $order->additional_charges = [];
            }
            $order->date = $order->created_at;
            return $order;
        });
        $orders = $orders->makeHidden(['created_at']);

        $item_limit = $request->input('item_per_page', 10);
        $item_offset = (($request->input('item_page', 0)) - 1) * $item_limit;
        $data = array(
            "stores" => $sellers,
            "orders" => $orders,
            "orders_total" => $orders_total,
            "counts" => $counts
        );
        return CommonHelper::responseWithData($data);
    }

    public function view($id)
    {
        $data = CommonHelper::getOrderDetails($id);
        if (!$data["order"]) {
            return CommonHelper::responseError("Order Not found!");
        }

        // Task #9: Add GST information to order details response
        $order = $data["order"];
        $order->gst_info = [
            'cgst_amount' => (float) ($order->cgst_amount ?? 0),
            'sgst_amount' => (float) ($order->sgst_amount ?? 0),
            'igst_amount' => (float) ($order->igst_amount ?? 0),
            'total_gst' => (float) (($order->cgst_amount ?? 0) + ($order->sgst_amount ?? 0) + ($order->igst_amount ?? 0)),
            'is_intra_state' => (bool) ($order->is_intra_state ?? true),
            'company_state' => (string) ($order->company_state ?? ''),
            'customer_state' => (string) ($order->customer_state ?? ''),
            'company_gstin' => (string) ($order->company_gstin ?? ''),
            'company_pan' => (string) ($order->company_pan ?? ''),
        ];

        // Add per-item GST breakdown
        foreach ($data['order_items'] as &$oi) {
            $oi->gst_info = [
                'cgst_amount' => (float) ($oi->cgst_amount ?? 0),
                'sgst_amount' => (float) ($oi->sgst_amount ?? 0),
                'igst_amount' => (float) ($oi->igst_amount ?? 0),
                'total_gst' => (float) (($oi->cgst_amount ?? 0) + ($oi->sgst_amount ?? 0) + ($oi->igst_amount ?? 0)),
                'gst_rate' => (float) ($oi->gst_rate ?? 0),
                'hsn_code' => (string) ($oi->hsn_code ?? ''),
            ];
        }

        // Only delivery boys in the order's country can be assigned.
        $data["deliveryBoys"] = $this->getDeliveryBoysWithTranslations(
            $data["order"]->country_id ?? null,
            $data["order"]->zone_id ?? null
        );

        // Store details (translated name + location/contact) — order level and per item.
        $data['order']->store_info = CommonHelper::storeInfo($data['order']->store_id ?? null);
        foreach ($data['order_items'] as $oi) {
            $oi->store_info = CommonHelper::storeInfo($oi->store_id ?? null);
        }

        // Delivery-boy ↔ customer chat visibility. Quick = order-level (forward only, never
        // returnable); ecommerce = per item (forward delivery, or an active return pickup).
        $data['order']->is_delivery_boy_chat_visible = CommonHelper::isDeliveryBoyChatVisibleForward(
            $data['order']->delivery_boy_id ? (int) $data['order']->delivery_boy_id : null,
            (int) ($data['order']->active_status ?? 0)
        );
        if (($data['order']->channel ?? '') === 'ecommerce') {
            foreach ($data['order_items'] as $oi) {
                $oi->is_delivery_boy_chat_visible = CommonHelper::isDeliveryBoyChatVisibleForOrderItem(
                    (int) $oi->id,
                    $oi->delivery_boy_id ? (int) $oi->delivery_boy_id : null,
                    (int) ($oi->active_status ?? 0)
                );
            }
        }

        $shapeTimeline = function ($row) {
            $sid = (int) $row->status;
            $key = OrderStatusList::getTranslationKey($sid);
            return [
                'status'      => $sid,
                'status_name' => $key !== '' ? __($key) : (string) $row->status,
                'created_by'  => $row->createdByName,
                'user_type'   => $row->user_type,
                'datetime'    => $row->displayDateTime,
            ];
        };

        // Single `timeline` param: order-wise for quick (order_item_id = 0); for ecommerce
        // it is embedded per item inside each order_items entry instead.
        if (($data['order']->channel ?? '') === 'ecommerce') {
            $data["timeline"] = [];
            $perItem = OrderStatus::where('order_id', $id)
                ->where('order_item_id', '!=', 0)
                ->orderBy('id', 'ASC')->get()
                ->groupBy('order_item_id')
                ->map(fn ($rows) => $rows->map($shapeTimeline)->values());
            foreach ($data['order_items'] as $oi) {
                $timeline = $perItem->get($oi->id, collect())->values();
                // If the item was returned, continue the timeline with the return flow
                // (after the "returned" entry).
                $returnRequestId = ReturnRequest::where('order_item_id', $oi->id)->value('id');
                if ($returnRequestId) {
                    $returnTimeline = collect(CommonHelper::getReturnRequestTimeline($returnRequestId))
                        ->map(fn ($t) => [
                            'status'      => $t['status'],
                            'status_name' => $t['status_name'],
                            'created_by'  => $t['updated_by'] ?? '',
                            'user_type'   => null,
                            'datetime'    => $t['datetime'],
                            'is_return'   => true,
                        ]);
                    $timeline = $timeline->concat($returnTimeline)->values();
                }
                $oi->timeline = $timeline;
            }
        } else {
            $data["timeline"] = OrderStatus::where('order_id', $id)
                ->where('order_item_id', 0)
                ->orderBy('id', 'ASC')->get()
                ->map($shapeTimeline)->values();
        }

        return CommonHelper::responseWithData($data);
    }

    public function downloadOrderInvoice(Request $request)
    {
        $data = CommonHelper::getOrderDetails($request->order_id, true);
        if (!$data["order"]) {
            return CommonHelper::responseError("Order Not found!");
        }
        CommonHelper::AdditionalChargesArray($data['order']);
        return CommonHelper::invoicePdfFromData($data, $request->order_id);
    }

    /** Download a single order item's invoice (ecommerce, item-wise). */
    public function downloadOrderItemInvoice(Request $request)
    {
        $item = OrderItem::find($request->order_item_id);
        if (!$item) {
            return CommonHelper::responseError('order_item_not_found');
        }
        $data = CommonHelper::getOrderDetails($item->order_id);
        if (!$data["order"]) {
            return CommonHelper::responseError("Order Not found!");
        }

        // Keep only this item, and present its amounts as the invoice totals.
        $data['order_items'] = collect($data['order_items'])->where('id', $item->id)->values();
        $order = $data['order'];
        $order->delivery_charge   = $item->delivery_charge;
        $order->additional_charges = $item->additional_charges;
        $order->surge_charges     = $item->surge_charges;
        $order->promo_discount    = $item->promo_discount;
        $order->wallet_balance    = $item->wallet_balance;
        $order->remaining_total   = $item->sub_total;
        $order->final_total       = $item->final_total;
        $order->remaining_final   = max(0, (float) $item->final_total - (float) $item->wallet_balance);
        // Item refunded (cancelled/returned): nothing left payable, surface its refund.
        if (in_array((int) $item->active_status, [7, 8], true)) {
            $order->remaining_final = 0;
            $order->refund_amount   = (float) $item->refund_amount;
        }
        $order->active_status = $item->active_status;
        // This invoice is for the single item — show the item's own invoice number.
        $order->invoice_number = $item->invoice_number ?: ($order->invoice_number . '-' . $item->id);
        $data['order'] = $order;

        return CommonHelper::invoicePdfFromData($data, $item->invoice_number ?: ($item->order_id . '-' . $item->id));
    }

    public function delete(Request $request)
    {
        if (isset($request->id)) {
            $order = Order::find($request->id);
            if ($order) {
                $order->delete();
                return CommonHelper::responseSuccess('order_deleted_successfully');
            } else {
                return CommonHelper::responseSuccess("Order Already Deleted!");
            }
        }
    }

    public function deleteItem(Request $request)
    {
        if (isset($request->id)) {
            $orderItem = OrderItem::find($request->id);
            if ($orderItem) {
                $orderItem->delete();
                return CommonHelper::responseSuccess('order_item_deleted_successfully');
            } else {
                return CommonHelper::responseSuccess("Order Item Already Deleted!");
            }
        }
    }


    public function updateStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'status_id' => 'required',
        ], [
            'order_id.required' => 'The Order id field is required.',
            'status_id.required' => 'The status field is required.',
        ]);
        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }
        $order = Order::find($request->order_id);
        if (empty($order)) {
            return CommonHelper::responseError("Order Not found!");
        }
        $selectedStatus = OrderStatusList::where('id', $request->status_id)->value('status');

        if ($order->active_status == $request->status_id) {
            return CommonHelper::responseError("This Order is already " . $selectedStatus . "!");
        }

        if ($order->active_status == 6 && $request->status_id < 6) {
            return CommonHelper::responseError("This Order is Delivered");
        }

        if ($order->active_status == OrderStatusList::$paymentPending) {
            return CommonHelper::responseError("cannot_assign_delivery_boy_while_payment_pending");
        }

        if ($order->active_status == OrderStatusList::$returned || $order->active_status == OrderStatusList::$cancelled) {
            return CommonHelper::responseError("Order is Cancelled OR Returned.");
        }

        // Ready-for-pickup / picked-up / out-for-delivery require an assigned delivery boy
        $needsDeliveryBoy = [OrderStatusList::$readyForPickup, OrderStatusList::$pickedUp, OrderStatusList::$outForDelivery];
        if (in_array((int) $request->status_id, $needsDeliveryBoy, true)) {
            $assignedBoy = $request->delivery_boy_id ?? $order->delivery_boy_id;
            if (empty($assignedBoy) || (int) $assignedBoy === 0) {
                return CommonHelper::responseError('please_assign_a_delivery_boy_first');
            }
        }

        // OTP check on delivery for quick orders — only enforced when a delivery boy marks
        // delivered (admins/other roles bypass it). Validated against orders.otp (> 0 = OTP enabled).
        if ((int) $request->status_id === OrderStatusList::$delivered && $order->channel === 'quick' && (int) $order->otp > 0
            && (int) auth()->user()->role_id === Role::$roleDeliveryBoy) {
            if (empty($request->otp) || (int) $request->otp !== (int) $order->otp) {
                return CommonHelper::responseError('invalid_otp');
            }
        }

        DB::beginTransaction();
        try {

            if ($order->active_status != $request->status_id) {

                $effectiveDeliveryBoyId = (isset($request->delivery_boy_id) && $request->delivery_boy_id != "" && $request->delivery_boy_id != 0)
                    ? (int) $request->delivery_boy_id
                    : (int) ($order->delivery_boy_id ?? 0);

                if ($effectiveDeliveryBoyId) {

                    // Delivery Boy cash collection add and cash_received update with update balance of delivery boy start
                    if ($request->status_id == OrderStatusList::$delivered && ($deliveryBoy = DeliveryBoy::find($effectiveDeliveryBoyId))) {

                        CommonHelper::addDeliveryBoySettlement($deliveryBoy->id, $order->delivery_boy_bonus_amount, DeliveryBoySettlement::$typeCredit);
                        $deliveryBoy->refresh();

                        if ($order->payment_method == DeliveryBoyCashCollection::$paymentTypeCod) {

                            $transactionData = [
                                'user_id' => $order->user_id,
                                'order_id' => $order->id,
                                'delivery_boy_id' => $deliveryBoy->id,
                                'type' => $order->payment_method,
                                'amount' => $order->remaining_final,
                                'status' => Transaction::$statusSuccess,
                                'message' => 'cod_collected_on_delivery',
                                'transaction_date' => now(), // Cleaner than date('Y-m-d H:i:s')
                            ];

                            $transaction = DeliveryBoyCashCollection::create($transactionData);

                            $order->transaction_id = $transaction->id ?? 0;

                            $deliveryBoy->cash_received = floatval($deliveryBoy->cash_received) + floatval($order->remaining_final);
                        }

                        $deliveryBoy->save();
                    }

                    $order->delivery_boy_id = $effectiveDeliveryBoyId;
                }

                $order->active_status = $request->status_id;
                $order->save();

                if ($request->status_id == OrderStatusList::$delivered) {
                    $order = Order::with('user', 'items.productVariant.product')->find($request->order_id);
                    $user = $order->user;

                    // Credit wallet-type promo cashback now that the order is delivered.
                    CommonHelper::creditOrderCashback($order);

                    // Refer-&-earn is country-wise, from the ORDER's country: qualify by that
                    // country's threshold, credit in that country's currency into the
                    // referrer's wallet FOR THAT COUNTRY.
                    $orderCountry = CommonHelper::resolveOrderCountry($order);
                    $referralCountryId = $order->country_id ?: ($orderCountry->id ?? null);
                    $referralMinOrderAmount = (float) ($orderCountry->referral_min_order_amount ?? 0);
                    $referralCredit = (float) ($orderCountry->referral_credit_first_order ?? 0);
                    $referredCredit = (float) ($orderCountry->referral_credit_referred ?? 0);

                    if ($user && $user->friends_code && $order->final_total >= $referralMinOrderAmount) {
                        // Check if this is the user's FIRST delivered order
                        $deliveredOrdersCount = Order::where('user_id', $user->id)
                            ->where('active_status', OrderStatusList::$delivered)
                            ->where('id', '!=', $order->id)  // Exclude current order
                            ->count();

                        if ($deliveredOrdersCount === 0) {  // This means it's the first order

                            $now = Carbon::now();

                            $canCreditReferral = true;

                            foreach ($order->items as $item) {
                                // Frozen policy snapshot on the order item.
                                if ($item->return_status == 1 && $item->return_days > 0) {
                                    $canCreditReferral = false;
                                }
                            }

                            if ($canCreditReferral) {
                                // Credit referral bonus immediately
                                $referrer = User::where('referral_code', $user->friends_code)->first();

                                if ($referrer && !CommonHelper::referralLimitReached($referrer->id, $orderCountry)) {
                                    // Referrer bonus → referrer's wallet for the ORDER's country.
                                    if ($referralCredit > 0) {
                                        $current = (float) CommonHelper::getUserWalletBalance($referrer->id, $referralCountryId);
                                        CommonHelper::updateUserWalletBalance($current + $referralCredit, $referrer->id, $referralCountryId);
                                        CommonHelper::addWalletTransaction($order->id, 0, $referrer->id, 'credit', $referralCredit, 'wallet_refer_earn_first_order_bonus');
                                        CommonHelper::sendWalletNotification($referrer, $referralCredit, 'wallet_referral_bonus_customer', 'customer_wallet_referral_bonus', [], $referralCountryId);
                                    }
                                    // Referred-user bonus → the new user's wallet for the same country.
                                    if ($referredCredit > 0) {
                                        $currentReferred = (float) CommonHelper::getUserWalletBalance($user->id, $referralCountryId);
                                        CommonHelper::updateUserWalletBalance($currentReferred + $referredCredit, $user->id, $referralCountryId);
                                        CommonHelper::addWalletTransaction($order->id, 0, $user->id, 'credit', $referredCredit, 'wallet_refer_earn_referred_user_bonus');
                                        CommonHelper::sendWalletNotification($user, $referredCredit, 'wallet_referral_bonus_customer', 'customer_wallet_referral_bonus', [], $referralCountryId);
                                    }
                                }
                            } else {
                                // Queue a job to check again after max return days
                                $maxReturnDays = $order->items->filter(function ($item) {
                                    return $item->return_status == 1 && $item->return_days > 0;
                                })->max(function ($item) {
                                    return $item->return_days;
                                }) ?? 0;

                                $deliveredStatus = OrderStatus::where('order_id', $order->id)
                                    ->where('status', OrderStatusList::$delivered)
                                    ->orderBy('created_at', 'desc')
                                    ->first();

                                $deliveredAt = $deliveredStatus ? Carbon::parse($deliveredStatus->created_at) : Carbon::parse($order->updated_at);
                                $now = Carbon::now();

                                if ($maxReturnDays > 0) {

                                    $returnPeriodEnd = $deliveredAt->copy()->addDays($maxReturnDays);
                                    $delay = $now->diffInSeconds($returnPeriodEnd, false); // false: signed diff

                                    if ($delay > 0) {
                                        ProcessReferralBonusAfterReturnPeriod::dispatch($order->id)->delay(Carbon::now()->addSeconds($delay));
                                    }
                                }
                            }
                        }
                    }
                }

                $excludedStatuses = [OrderStatusList::$cancelled, OrderStatusList::$returned];

                // Update the order items
                $query = OrderItem::where("order_id", $request->order_id)
                    ->whereNotIn("active_status", $excludedStatuses)
                    ->update(['active_status' => $request->status_id]);

                $orderStatus = array();
                $orderStatus["order_id"] = $request->order_id;
                $orderStatus['order_item_id'] = 0;
                $orderStatus["status"] = $request->status_id;
                $orderStatus["created_by"] = auth()->user()->id;
                $orderStatus["user_type"] = auth()->user()->role_id;
                CommonHelper::setOrderStatus($orderStatus);
            } else {
                $status = OrderStatusList::find($request->status_id);
                return CommonHelper::responseError('status_is_already' . " " . $status->status);
            }
            DB::commit();
        } catch (\Exception $e) {
            Log::info("Error : " . $e->getMessage());
            DB::rollBack();
            throw $e;
            return CommonHelper::responseError('something_went_wrong');
        }

        $order = Order::with('items')->where("id", $request->order_id)->first();

        if (!empty($order)) {
            try {
                dispatch(function () use ($order) {
                    CommonHelper::sendNotificationOrderStatus($order, 'order_item_status_update');
                    CommonHelper::sendOrderNotificationsToAdmins($order, 'order_status_update', $order->delivery_boy_id ?? null);
                })->afterResponse();
            } catch (\Exception $e) {
                Log::error("Order status notification error: " . $e->getMessage());
            }

            try {
                dispatch(new SendEmailJob($order))->afterResponse();
            } catch (\Exception $e) {
                Log::error('update_order_status_by_delivery_boy_send_mail_error' . ":", [$e->getMessage()]);
            }

            try {
                dispatch(function () use ($order) {
                    CommonHelper::sendSmsOrderStatus($order, $order->active_status);
                })->afterResponse();
            } catch (\Exception $e) {
                Log::error('update_order_status_by_delivery_boy_send_sms_error' . ":", [$e->getMessage()]);
            }
        }

        return CommonHelper::responseSuccess('order_updated_successfully');
    }

    /**
     * Admin cancel — full refund. Unlike a customer cancel (which withholds delivery /
     * non-refundable charges), an admin cancel is not the customer's fault (out of
     * stock etc), so the customer is refunded everything they paid:
     *   - prepaid: the item's full final_total (or the whole order's, for quick)
     *   - COD: only the wallet amount applied at checkout (cash was never collected)
     * Quick orders cancel order-wise (order_id); ecommerce cancels item-wise (order_item_id).
     */
    public function adminCancel(Request $request)
    {
        if (empty($request->order_id) && empty($request->order_item_id)) {
            return CommonHelper::responseError('order_item_id_or_order_id_is_required');
        }
        if (trim((string) $request->cancellation_reason) === '') {
            return CommonHelper::responseError('cancellation_reason_is_required');
        }

        $orderItem = null; // set only on the single-item branch; drives which notifications fire

        DB::beginTransaction();
        try {
            if (!empty($request->order_item_id)) {
                // Ecommerce — single item.
                $orderItem = OrderItem::find($request->order_item_id);
                if (!$orderItem) {
                    return CommonHelper::responseError('Order Item Not found.');
                }
                $order = Order::find($orderItem->order_id);
                if (in_array((int) $orderItem->active_status, [OrderStatusList::$cancelled, OrderStatusList::$returned], true)) {
                    return CommonHelper::responseError('order_item_is_already_cancelled');
                }
                if ((int) $orderItem->active_status === OrderStatusList::$delivered) {
                    return CommonHelper::responseError('delivered_item_cannot_be_cancelled');
                }
                $this->adminCancelItem($order, $orderItem, $request->cancellation_reason ?? null);
                CommonHelper::syncEcommerceOrderStatus($order);
            } else {
                // Quick — whole order.
                $order = Order::find($request->order_id);
                if (!$order) {
                    return CommonHelper::responseError('Order Not found.');
                }
                if (in_array((int) $order->active_status, [OrderStatusList::$cancelled, OrderStatusList::$returned], true)) {
                    return CommonHelper::responseError('order_is_already_cancelled_or_returned');
                }
                if ((int) $order->active_status === OrderStatusList::$delivered) {
                    return CommonHelper::responseError('delivered_order_cannot_be_cancelled');
                }
                $items = OrderItem::where('order_id', $order->id)
                    ->where('active_status', '!=', OrderStatusList::$cancelled)
                    ->get();
                foreach ($items as $it) {
                    $this->adminCancelItem($order, $it, $request->cancellation_reason ?? null);
                }

                $isCod = $order->payment_method === Transaction::$paymentTypeCod;
                $orderRefund = $isCod
                    ? floatval($order->wallet_balance)
                    : floatval($order->final_total) + floatval($order->wallet_balance);
                if ($orderRefund > 0) {
                    CommonHelper::addUserWalletBalance($orderRefund, $order->user_id, $order->country_id);
                    CommonHelper::addWalletTransaction($order->id, 0, $order->user_id, 'credit', $orderRefund, 'wallet_order_item_cancelled');
                    CommonHelper::notifyWalletRefundCancelled($order, $items->first(), $orderRefund);
                    $order->refund_amount = floatval($order->refund_amount) + $orderRefund;
                }

                $order->active_status = OrderStatusList::$cancelled;
                $order->remaining_total = 0;
                $order->remaining_final = 0;
                $order->wallet_balance = 0;
                $order->save();

                CommonHelper::setOrderStatus([
                    'order_id'      => $order->id,
                    'order_item_id' => 0,
                    'status'        => OrderStatusList::$cancelled,
                    'created_by'    => auth()->user()->id,
                    'user_type'     => auth()->user()->role_id,
                ]);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('admin_cancel_error: ' . $e->getMessage());
            return CommonHelper::responseError('something_went_wrong');
        }

        $cancelledItem = $orderItem;
        $order = Order::with('items')->find($order->id);
        try {
            dispatch(function () use ($order, $cancelledItem) {
                if ($cancelledItem) {
                    CommonHelper::sendOrderItemStatusMailNotification($cancelledItem, 'order_item_status_update');
                    CommonHelper::sendSmsOrderStatus($cancelledItem, OrderStatusList::$cancelled);
                } else {
                    // Whole (quick) order.
                    CommonHelper::sendNotificationOrderStatus($order);
                    CommonHelper::sendMailOrderStatus($order, false, 'order_status_update');
                    CommonHelper::sendSmsOrderStatus($order, OrderStatusList::$cancelled);
                    CommonHelper::sendOrderNotificationsToAdmins($order, 'order_status_update', $order->delivery_boy_id ?? null);
                }
            })->afterResponse();
        } catch (\Exception $e) {
            Log::error('admin_cancel_notification_error: ' . $e->getMessage());
        }

        return CommonHelper::responseSuccessWithData('order_cancelled_successfully', $order);
    }

    /** Full-refund + cancel a single order item (admin). */
    private function adminCancelItem($order, $orderItem, $reason = null): void
    {
        $isCod = $order->payment_method === Transaction::$paymentTypeCod;

        $refund = $isCod
            ? floatval($orderItem->wallet_balance)
            : floatval($orderItem->final_total) + floatval($orderItem->wallet_balance);

        if ($refund > 0) {
            CommonHelper::addUserWalletBalance($refund, $order->user_id, $order->country_id);
            CommonHelper::addWalletTransaction($order->id, $orderItem->id, $order->user_id, 'credit', $refund, 'wallet_order_item_cancelled');
            CommonHelper::notifyWalletRefundCancelled($order, $orderItem, $refund);
        }

        $orderItem->refund_amount = $refund;
        $orderItem->active_status = OrderStatusList::$cancelled;
        $orderItem->cancellation_reason = $reason;
        $orderItem->canceled_at = now();
        $orderItem->save();

        // Restock per-store (PVSS).
        ProductHelper::restockStock($orderItem->product_variant_id, $orderItem->store_id, (int) $orderItem->quantity);

        // Reduce the order's outstanding totals by this item.
        $order->refund_amount = floatval($order->refund_amount) + $refund;
        $order->remaining_total = max(0, floatval($order->remaining_total) - floatval($orderItem->sub_total));
        $order->remaining_final = max(0, floatval($order->remaining_final) - $refund);
        if (floatval($order->wallet_balance) > 0) {
            $order->wallet_balance = max(0, floatval($order->wallet_balance) - floatval($orderItem->wallet_balance));
        }
        $order->save();
    }

    public function assignDeliveryBoy(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'delivery_boy_id' => 'required',
        ], [
            'order_id.required' => 'The Order id field is required.',
            'delivery_boy_id.required' => 'The delivery boy field is required.',
        ]);

        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        // delivery_boy_id = 0 unassigns the order.
        if ((int) $request->delivery_boy_id === 0) {
            $order = Order::find($request->order_id);
            if (!$order) {
                return CommonHelper::responseError('Order Not found!');
            }
            if (in_array((int) $order->active_status, [OrderStatusList::$cancelled, OrderStatusList::$returned, OrderStatusList::$delivered], true)) {
                return CommonHelper::responseError('delivery_boy_cannot_be_changed_for_this_order');
            }
            $order->delivery_boy_id = 0;
            $order->save();
            return CommonHelper::responseSuccess('updated_successfully');
        }

        $deliveryBoy = DeliveryBoy::find($request->delivery_boy_id);
        if (empty($deliveryBoy)) {
            return CommonHelper::responseSuccess('delivery_boy_not_found');
        }
        $order = Order::find($request->order_id);

        // A delivery boy may only serve orders in their own country.
        if ($order && (int) $order->country_id !== (int) $deliveryBoy->country_id) {
            return CommonHelper::responseError('delivery_boy_country_mismatch');
        }

        if ($order) {
            // Delivery boy can't be changed once the order is delivered / cancelled / returned.
            if (in_array((int) $order->active_status, [OrderStatusList::$cancelled, OrderStatusList::$returned, OrderStatusList::$delivered], true)) {
                return CommonHelper::responseError('delivery_boy_cannot_be_changed_for_this_order');
            }
            if ($order->delivery_boy_id == $request->delivery_boy_id) {
                return CommonHelper::responseError('this_delivery_boy_already_assign');
            }
            if ($order->active_status == OrderStatusList::$paymentPending) {
                return CommonHelper::responseError('cannot_assign_delivery_boy_while_payment_pending');
            }

            $final_total = floatval($order->total);

            $bonus_type = $deliveryBoy->bonus_type;
            $bonus_details['final_total'] = $final_total;
            $bonus_details['bonus_type'] = $bonus_type;
            $bonus_amount = 0;
            if ($bonus_type == DeliveryBoy::$bonusCommission) {

                $bonus_percentage = floatval($deliveryBoy->bonus_percentage);
                $bonus_min_amount = floatval($deliveryBoy->bonus_min_amount);
                $bonus_max_amount = floatval($deliveryBoy->bonus_max_amount);

                $bonus_amount = floatval(($final_total * $bonus_percentage) / 100);

                if ($bonus_amount < $bonus_min_amount && $bonus_min_amount != 0) {
                    $bonus_amount = $bonus_min_amount;
                }

                if ($bonus_amount > $bonus_max_amount && $bonus_max_amount != 0) {
                    $bonus_amount = $bonus_max_amount;
                }

                $bonus_details['bonus_type_name'] = DeliveryBoy::$commission;
                $bonus_details['bonus_percentage'] = $bonus_percentage;
                $bonus_details['bonus_min_amount'] = $bonus_min_amount;
                $bonus_details['bonus_max_amount'] = $bonus_max_amount;
                $bonus_details['bonus_amount'] = $bonus_amount;
            } else {
                $bonus_details['bonus_type_name'] = DeliveryBoy::$fixed;
            }
            $bonus_details['bonus_amount'] = $bonus_amount;

            $order->delivery_boy_bonus_details = $bonus_details;
            $order->delivery_boy_bonus_amount = $bonus_amount;

            $order->delivery_boy_id = $request->delivery_boy_id;
            $order->save();

            try {
                dispatch(function () use ($order) {
                    CommonHelper::sendNotificationOrderAssignDeliveryBoy($order);
                    CommonHelper::sendMailOrderStatus($order, true);
                })->afterResponse();
            } catch (\Exception $e) {
                Log::error("Delivery boy assigned on order Send mail error :", [$e->getMessage()]);
            }

            return CommonHelper::responseSuccess('delivery_boy_assigned_successfully_for_this_order');
        } else {
            return CommonHelper::responseError('order_not_found');
        }
    }

    /** Bonus details + amount for a delivery boy over a base total (commission/fixed). */
    private function deliveryBoyBonus(DeliveryBoy $deliveryBoy, float $baseTotal): array
    {
        $bonus_type = $deliveryBoy->bonus_type;
        $bonus_details = ['final_total' => $baseTotal, 'bonus_type' => $bonus_type];
        $bonus_amount = 0;
        if ($bonus_type == DeliveryBoy::$bonusCommission) {
            $bonus_percentage = floatval($deliveryBoy->bonus_percentage);
            $bonus_min_amount = floatval($deliveryBoy->bonus_min_amount);
            $bonus_max_amount = floatval($deliveryBoy->bonus_max_amount);
            $bonus_amount = floatval(($baseTotal * $bonus_percentage) / 100);
            if ($bonus_amount < $bonus_min_amount && $bonus_min_amount != 0) {
                $bonus_amount = $bonus_min_amount;
            }
            if ($bonus_amount > $bonus_max_amount && $bonus_max_amount != 0) {
                $bonus_amount = $bonus_max_amount;
            }
            $bonus_details['bonus_type_name'] = DeliveryBoy::$commission;
            $bonus_details['bonus_percentage'] = $bonus_percentage;
            $bonus_details['bonus_min_amount'] = $bonus_min_amount;
            $bonus_details['bonus_max_amount'] = $bonus_max_amount;
        } else {
            $bonus_details['bonus_type_name'] = DeliveryBoy::$fixed;
        }
        $bonus_details['bonus_amount'] = $bonus_amount;
        return [$bonus_details, $bonus_amount];
    }

    /** Assign (or unassign with 0) a delivery boy to a single ecommerce order item. */
    public function assignDeliveryBoyToItem(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_item_id'   => 'required|integer',
            'delivery_boy_id' => 'required|integer',
        ]);
        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        $item = OrderItem::find($request->order_item_id);
        if (!$item) {
            return CommonHelper::responseError('order_item_not_found');
        }
        $order = Order::find($item->order_id);
        if (!$order || $order->channel !== 'ecommerce') {
            return CommonHelper::responseError('item_assignment_only_for_ecommerce_orders');
        }
        // No fulfillment before payment: an unpaid order/item can't get a delivery boy.
        if ((int) $item->active_status === OrderStatusList::$paymentPending
            || (int) $order->active_status === OrderStatusList::$paymentPending) {
            return CommonHelper::responseError('cannot_assign_delivery_boy_while_payment_pending');
        }
        if (in_array((int) $item->active_status, [OrderStatusList::$cancelled, OrderStatusList::$returned, OrderStatusList::$delivered], true)) {
            return CommonHelper::responseError('delivery_boy_cannot_be_changed_for_this_item');
        }

        // Block if delivery boy already assigned — locked once set.
        if ((int) $item->delivery_boy_id > 0) {
            return CommonHelper::responseError('fulfillment_already_set_with_delivery_boy');
        }

        // Block if courier tracking is already set (locked once set).
        if (!empty($item->tracking_id)) {
            return CommonHelper::responseError('fulfillment_already_set_with_courier_tracking');
        }

        // A delivery boy may only serve items in their own country.
        $assignBoy = DeliveryBoy::find($request->delivery_boy_id);
        if (!$assignBoy || (int) $order->country_id !== (int) $assignBoy->country_id) {
            return CommonHelper::responseError('delivery_boy_country_mismatch');
        }

        $deliveryBoy = DeliveryBoy::find($request->delivery_boy_id);
        if (!$deliveryBoy) {
            return CommonHelper::responseError('delivery_boy_not_found');
        }
        if ((int) $item->delivery_boy_id === (int) $request->delivery_boy_id) {
            return CommonHelper::responseError('this_delivery_boy_already_assign');
        }

        // Bonus is computed on the item's own payable total.
        [$bonus_details, $bonus_amount] = $this->deliveryBoyBonus($deliveryBoy, floatval($item->final_total));
        $item->delivery_boy_bonus_details = $bonus_details;
        $item->delivery_boy_bonus_amount = $bonus_amount;
        $item->delivery_boy_id = $request->delivery_boy_id;
        // Assigning a delivery boy clears any courier tracking.
        $item->courier_agency = null;
        $item->tracking_id    = null;
        $item->tracking_url   = null;
        $item->save();

        return CommonHelper::responseSuccess('delivery_boy_assigned_successfully_for_this_order');
    }

    public function updateItemsStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required',
            'status_id' => 'required',
        ], [
            'ids.required' => 'The Item id field is required.',
            'status_id.required' => 'The status field is required.',
        ]);
        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }
        $ids = explode(",", $request->ids);

        // Quick orders carry one status for the whole order — block per-item updates;
        // admin must use the order-level status update instead.
        $quickOrderItem = OrderItem::join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereIn('order_items.id', $ids)
            ->where('orders.channel', 'quick')
            ->exists();
        if ($quickOrderItem) {
            return CommonHelper::responseError(__('quick_order_status_managed_at_order_level'));
        }

        // No status change while payment is still pending (item- or order-level).
        $paymentPending = OrderItem::join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereIn('order_items.id', $ids)
            ->where(function ($q) {
                $q->where('order_items.active_status', OrderStatusList::$paymentPending)
                    ->orWhere('orders.active_status', OrderStatusList::$paymentPending);
            })
            ->exists();
        if ($paymentPending) {
            return CommonHelper::responseError('cannot_update_status_while_payment_pending');
        }

        // Out-for-delivery requires each item to have either a delivery boy OR courier tracking set.
        if ((int) $request->status_id === OrderStatusList::$outForDelivery) {
            foreach ($ids as $id) {
                $oi = OrderItem::find($id);
                if ($oi && (int) $oi->delivery_boy_id === 0 && empty($oi->tracking_id)) {
                    return CommonHelper::responseError('please_assign_a_delivery_boy_or_set_courier_tracking_first');
                }
            }
        }

        $affectedOrderIds = [];
        $updatedItemIds = [];
        foreach ($ids as $key => $id) {
            $orderItem = OrderItem::find($id);
            $orderItem->active_status = $request->status_id;
            $orderItem->save();
            $affectedOrderIds[$orderItem->order_id] = true;
            $updatedItemIds[] = (int) $id;

            $orderStatus = array();
            $orderStatus["order_id"] = $orderItem->order_id;
            $orderStatus["order_item_id"] = $id;
            $orderStatus["status"] = $request->status_id;
            $orderStatus["created_by"] = auth()->user()->id;
            $orderStatus["user_type"] = auth()->user()->role_id;
            CommonHelper::setOrderStatus($orderStatus);

            // Delivered → credit the assigned delivery boy's bonus + COD cash (once).
            if ((int) $request->status_id === OrderStatusList::$delivered) {
                CommonHelper::creditOrderItemDelivery($orderItem->fresh());
            }
        }
        // Ecommerce order status follows its items (all delivered → delivered, etc).
        foreach (array_keys($affectedOrderIds) as $oid) {
            CommonHelper::syncEcommerceOrderStatus($oid);

            // Panel + FCM notification to admins/boy for each affected order.
            $notifyOrder = Order::with('items')->find($oid);
            if ($notifyOrder) {
                try {
                    dispatch(function () use ($notifyOrder) {
                        CommonHelper::sendOrderNotificationsToAdmins($notifyOrder, 'order_status_update', $notifyOrder->delivery_boy_id ?? null);
                    })->afterResponse();
                } catch (\Exception $e) {
                    Log::error("updateItemsStatus admin notification error: " . $e->getMessage());
                }
            }
        }

        // Customer push + SMS + mail per updated item — ecommerce items carry their own
        // status, so the customer is notified per item (payload includes order_item_id).
        try {
            dispatch(function () use ($updatedItemIds) {
                foreach ($updatedItemIds as $itemId) {
                    $item = OrderItem::find($itemId);
                    if ($item) {
                        CommonHelper::sendNotificationOrderStatus($item, 'order_item_status_update');
                        CommonHelper::sendSmsOrderStatus($item, $item->active_status);
                    }
                }
            })->afterResponse();
        } catch (\Exception $e) {
            Log::error("updateItemsStatus customer notification error: " . $e->getMessage());
        }
        try {
            foreach ($updatedItemIds as $itemId) {
                $item = OrderItem::find($itemId);
                if ($item) {
                    dispatch(new SendEmailJob($item, 'order_item_status_update'))->afterResponse();
                }
            }
        } catch (\Exception $e) {
            Log::error("updateItemsStatus mail error: " . $e->getMessage());
        }

        return CommonHelper::responseSuccess('order_updated_successfully');
    }

    public function cancelOrderItem(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_item_id' => 'required|integer',
            'cancellation_reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        $order_item = OrderItem::find($request->order_item_id);

        if (empty($order_item)) {
            return CommonHelper::responseError('order_item_not_found');
        }

        $order = Order::find($order_item->order_id);
        if (empty($order)) {
            return CommonHelper::responseError('order_not_found');
        }

        if ($order->active_status == OrderStatusList::$cancelled || $order->active_status == OrderStatusList::$returned) {
            return CommonHelper::responseError('order_is_already_cancelled_or_returned');
        }

        if ($order->active_status == OrderStatusList::$delivered) {
            return CommonHelper::responseError('order_is_already_delivered');
        }

        if (empty($order_item)) {
            return CommonHelper::responseError('order_item_not_found');
        }

        if ($order_item->active_status == OrderStatusList::$cancelled) {
            return CommonHelper::responseError('order_item_is_already_cancelled');
        }

        if ($order_item->active_status == OrderStatusList::$delivered || $order_item->active_status == OrderStatusList::$returned) {
            return CommonHelper::responseError('order_item_cannot_be_cancelled');
        }

        $postStatus = OrderStatusList::$cancelled;

        DB::beginTransaction();
        try {
            if ($order->channel === 'quick') {
                // Quick = single-store, one status. Cancelling any item cancels the
                // whole order: pre-cancel + restock siblings so the current item is the
                // "last item" and the full-order refund path settles everything once.
                $siblings = OrderItem::where('order_id', $order->id)
                    ->where('id', '!=', $order_item->id)
                    ->where('active_status', '!=', OrderStatusList::$cancelled)
                    ->get();
                foreach ($siblings as $sib) {
                    // Restock per-store (PVSS); product_variants.stock removed.
                    ProductHelper::restockStock($sib->product_variant_id, $sib->store_id, (int) $sib->quantity);
                    $sib->active_status = OrderStatusList::$cancelled;
                    $sib->cancellation_reason = $request->cancellation_reason;
                    $sib->canceled_at = now();
                    $sib->save();
                }
            }

            $itemNum = OrderItem::where("order_id", $order->id)->count();
            $lastItemNum = OrderItem::where("order_id", $order->id)
                ->where('active_status', '!=', OrderStatusList::$cancelled)
                ->count();

            if ($itemNum == 1 || $lastItemNum == 1) {
                $order_status = array();
                $order_status['order_id'] = $order->id;
                $order_status['order_item_id'] = $order_item->id;
                $order_status['status'] = $postStatus;
                $order_status['created_by'] = auth()->user()->id;
                $order_status['user_type'] = OrderStatus::$userTypeUser;
                CommonHelper::setOrderStatus($order_status);
                $order->active_status = OrderStatusList::$cancelled;
                $order->save();
            }

            $user = User::find($order->user_id);
            $currentBalance = floatval($user->balance);

            // Only charges flagged is_refundable are refunded on cancel. Legacy charges
            // without the flag default to refundable (preserves old behaviour).
            $additional_charges = CommonHelper::refundableCharges($order->additional_charges);
            $additional_charges_total = array_sum(array_column($additional_charges, 'amount'));

            // Surge charges — same handling as additional charges.
            $surge_charges = CommonHelper::refundableCharges($order->surge_charges);
            $surge_charges_total = array_sum(array_column($surge_charges, 'charge'));

            $isLastItem = ($itemNum == 1 || $lastItemNum == 1);

            $isCod = ($order->payment_method === Transaction::$paymentTypeCod);
            $isPaymentPending = ($order->active_status == OrderStatusList::$paymentPending);

            $allItemsSubTotal = floatval(OrderItem::where('order_id', $order->id)->sum('sub_total'));
            $itemSubTotal = floatval($order_item->sub_total);
            $itemShare = ($allItemsSubTotal > 0) ? ($itemSubTotal / $allItemsSubTotal) : 0;

            $refundable = 0;
            $walletRefund = 0;

            if (!$isCod && !$isPaymentPending) {
                // Non-COD: customer already paid, refund item amount proportionally
                $refundable = $itemSubTotal;

                if (floatval($order->promo_discount ?? 0) > 0) {
                    $refundable -= floatval($order->promo_discount) * $itemShare;
                }

                if (floatval($order->delivery_charge ?? 0) > 0) {
                    $refundable += floatval($order->delivery_charge) * $itemShare;
                }

                if ($additional_charges_total > 0) {
                    $refundable += $additional_charges_total * $itemShare;
                }

                if ($surge_charges_total > 0) {
                    $refundable += $surge_charges_total * $itemShare;
                }
            }

            // Wallet refund: applies to COD and non-COD but not payment pending (wallet was deducted at order time)
            if (!$isPaymentPending && floatval($order->wallet_balance ?? 0) > 0) {
                if ($isLastItem) {
                    $walletRefund = floatval($order->wallet_balance);
                } else {
                    $activeSubTotal = floatval(OrderItem::where('order_id', $order->id)
                        ->where('active_status', '!=', OrderStatusList::$cancelled)
                        ->sum('sub_total'));
                    $walletShare = ($activeSubTotal > 0) ? ($itemSubTotal / $activeSubTotal) : 0;
                    $walletRefund = floatval($order->wallet_balance) * $walletShare;
                }
                $refundable += $walletRefund;
                $order->wallet_balance = floatval($order->wallet_balance) - $walletRefund;
            }

            $refundable = max(0, round($refundable, 2));

            // Update order totals
            if ($isLastItem) {
                $order->remaining_total = 0;
                $order->remaining_final = $isCod ? 0 : 0;
                $order->wallet_balance = 0;
            } else {
                $order->remaining_total = max(0, floatval($order->remaining_total) - $itemSubTotal);
                $nonWalletRefund = $refundable - $walletRefund;
                $order->remaining_final = max(0, floatval($order->remaining_final) - $nonWalletRefund);
            }

            // Process refund to wallet
            if ($refundable > 0) {
                $new_balance = $currentBalance + $refundable;
                CommonHelper::updateUserWalletBalance($new_balance, $order->user_id);
                CommonHelper::addWalletTransaction($order->id, $order_item->id, $order->user_id, 'credit', $refundable, 'wallet_order_item_cancelled');
                CommonHelper::notifyWalletRefundCancelled($order, $order_item, $refundable);
            }

            $order->save();

            $order_item->active_status = $postStatus;
            $order_item->cancellation_reason = $request->cancellation_reason;
            $order_item->canceled_at = now();
            $order_item->save();

            // Restock the cancelled item per-store (PVSS).
            ProductHelper::restockStock($order_item->product_variant_id, $order_item->store_id, (int) $order_item->quantity);

            // Ecommerce: order status follows its remaining items.
            CommonHelper::syncEcommerceOrderStatus($order);

            if (isset($order->promo_code) && $order->promo_code != null && isset($order->promo_discount) && $order->promo_discount != null) {
                $promo_code = explode("(", $order->promo_code);
                $promoCodeModel = PromoCode::where('promo_code', $promo_code[0])->first();
                if ($promoCodeModel && isset($promoCodeModel->minimum_order_amount) && $order->total < $promoCodeModel->minimum_order_amount) {
                    CommonHelper::updateOrderPromoCode($order->id, $order->promo_discount);
                }
            }

            DB::commit();

            dispatch(function () use ($order, $order_item) {
                try {
                    CommonHelper::sendNotificationOrderStatus($order, 'order_item_status_update');
                } catch (\Exception $e) {
                    Log::error("Cancel order item notification error: " . $e->getMessage());
                }

                try {
                    CommonHelper::sendOrderItemStatusMailNotification($order_item, 'order_item_status_update');
                } catch (\Exception $e) {
                    Log::error("Cancel order item mail notification error: " . $e->getMessage());
                }

                try {
                    CommonHelper::sendSmsOrderStatus($order_item, OrderStatusList::$cancelled);
                } catch (\Exception $e) {
                    Log::error("Cancel order item SMS error: " . $e->getMessage());
                }
            })->afterResponse();

            return CommonHelper::responseSuccess('order_cancelled_successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Cancel order item error: " . $e->getMessage());
            return CommonHelper::responseError('something_went_wrong');
        }
    }

    private function getStoresWithTranslations(): array
    {
        $languageService = app(LanguageService::class);
        $defaultLang = $languageService->getDefaultLanguage();
        $defaultCode = $defaultLang ? $languageService->getLanguageCode($defaultLang->id) : 'en';
        $activeLangCodes = collect($languageService->getActiveLanguages())->pluck('code')->filter()->values()->all();

        $sellers = Store::select('id', 'name')
            ->where('status', 1)
            ->with('translations')
            ->orderBy('id', 'DESC')
            ->get();

        $result = [];
        foreach ($sellers as $seller) {
            $nameByCode = [];
            $all = $seller->getAllActiveLanguageTranslations();
            foreach ($all as $t) {
                $code = $t['language_code'] ?? '';
                if ($code !== '') {
                    $nameByCode[$code] = trim((string) ($t['name'] ?? ''));
                }
            }
            $defaultName = $nameByCode[$defaultCode] ?? $seller->getAttributeValue('name') ?? '';
            $nameObj = (object) [];
            foreach ($activeLangCodes as $code) {
                $nameObj->{$code} = ($nameByCode[$code] ?? '') !== '' ? $nameByCode[$code] : $defaultName;
            }
            if ((array) $nameObj === []) {
                $nameObj = (object) ['en' => $seller->getAttributeValue('name') ?? ''];
            }
            $result[] = [
                'id' => $seller->id,
                'name' => $nameObj,
            ];
        }
        return $result;
    }

    private function getDeliveryBoysWithTranslations($countryId = null, $zoneId = null): array
    {
        $languageService = app(LanguageService::class);
        $defaultLang = $languageService->getDefaultLanguage();
        $defaultCode = $defaultLang ? $languageService->getLanguageCode($defaultLang->id) : 'en';
        $activeLangCodes = collect($languageService->getActiveLanguages())->pluck('code')->filter()->values()->all();

        // Active riders serving this order's zone. Zone is the tighter constraint;
        // country is kept for older rows whose zone hasn't been set yet.
        $deliveryBoys = DeliveryBoy::where('status', 1)
            ->when($countryId, fn ($q) => $q->where('country_id', $countryId))
            ->when($zoneId, fn ($q) => $q->where('zone_id', $zoneId))
            ->with('translations')->orderBy('id', 'DESC')->get();

        $result = [];
        foreach ($deliveryBoys as $db) {
            $nameByCode = [];
            $all = $db->getAllActiveLanguageTranslations();
            foreach ($all as $t) {
                $code = $t['language_code'] ?? '';
                if ($code !== '') {
                    $nameByCode[$code] = trim((string) ($t['name'] ?? ''));
                }
            }
            $defaultName = $nameByCode[$defaultCode] ?? $db->getAttributeValue('name') ?? '';
            $nameObj = (object) [];
            foreach ($activeLangCodes as $code) {
                $nameObj->{$code} = ($nameByCode[$code] ?? '') !== '' ? $nameByCode[$code] : $defaultName;
            }
            if ((array) $nameObj === []) {
                $nameObj = (object) ['en' => $db->getAttributeValue('name') ?? ''];
            }
            $result[] = [
                'id' => $db->id,
                'name' => $nameObj,
                'pending_order_count' => $db->pending_order_count ?? 0,
                'driving_license_url' => $db->driving_license_url ?? null,
                'national_identity_card_url' => $db->national_identity_card_url ?? null,
            ];
        }
        return $result;
    }

    /** Save (or clear) courier tracking details on an ecommerce order item. Clears delivery boy. */
    public function saveOrderTracking(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_item_id' => 'required|integer',
        ]);
        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        $item = OrderItem::find($request->order_item_id);
        if (!$item) {
            return CommonHelper::responseError('order_item_not_found');
        }
        $order = Order::find($item->order_id);
        if (!$order || $order->channel !== 'ecommerce') {
            return CommonHelper::responseError('item_assignment_only_for_ecommerce_orders');
        }
        if (in_array((int) $item->active_status, [OrderStatusList::$cancelled, OrderStatusList::$returned, OrderStatusList::$delivered], true)) {
            return CommonHelper::responseError('cannot_update_tracking_for_this_item');
        }

        $tid = trim((string) ($request->tracking_id ?? ''));

        // Block if delivery boy is already assigned (locked once set).
        if ($tid !== '' && (int) $item->delivery_boy_id > 0) {
            return CommonHelper::responseError('fulfillment_already_set_with_delivery_boy');
        }

        $item->courier_agency = trim((string) ($request->courier_agency ?? '')) ?: null;
        $item->tracking_id    = $tid ?: null;
        $item->tracking_url   = trim((string) ($request->tracking_url ?? '')) ?: null;

        $item->save();
        return CommonHelper::responseSuccess('updated_successfully');
    }
}
