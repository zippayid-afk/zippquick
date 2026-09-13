<?php

namespace App\Http\Controllers\API\Customer;

use App\Helpers\CommonHelper;
use App\Helpers\ProductHelper;
use App\Helpers\Paypal;
use App\Helpers\Paystack;
use App\Helpers\TransactionHelper;
use App\Http\Controllers\Controller;
use App\Models\AppUsage;
use App\Models\Order;
use App\Models\Cart;
use App\Models\Store;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\OrderStatusList;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttributeValue;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\LiveTracking;
use App\Models\PromoCode;
use App\Models\ReturnRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use App\Jobs\SendEmailJob;
use App\Models\ReturnRequestStatus;
use App\Models\ReturnStatusList;
use App\Models\WalletTransaction;

class OrderApiController extends Controller
{
    public function placeOrder(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'payment_method' => 'required',
                'address_id' => 'required',
                'order_note' => 'nullable|string|max:256',
                'prescription' => 'nullable|array',
                'prescription.*' => 'file|mimes:jpg,jpeg,png,webp,pdf,svg|max:5120',
                // GST data from frontend (sent at checkout)
                'gst_inclusive' => 'nullable|boolean',
                'gst_rate' => 'nullable|numeric|min:0|max:100',
                'cgst_amount' => 'nullable|numeric|min:0',
                'sgst_amount' => 'nullable|numeric|min:0',
                'igst_amount' => 'nullable|numeric|min:0',
                'total_gst_amount' => 'nullable|numeric|min:0',
                'final_price_with_gst' => 'nullable|numeric|min:0',
            ], [
                'required' => 'The :attribute field is required.',
                'order_note.max' => 'Order note cannot exceed 256 characters.',
                'prescription.*.mimes' => __('prescription_must_be_an_image_or_pdf'),
                'prescription.*.max' => __('prescription_file_is_too_large'),
            ]);

            if ($validator->fails()) {
                return CommonHelper::responseError($validator->errors()->first());
            }

            $user = auth()->user();
            if (!isset($user->status) || $user->status == 0) {
                return CommonHelper::responseError(__('not_allowed_to_place_order_as_your_account_is_de_activated'));
            }

            // ---- Channel (quick = single-store instant; ecommerce = multi-store) ----
            $channel = strtolower(trim((string) $request->header('channel')));
            if (!in_array($channel, ['quick', 'ecommerce'], true)) {
                return CommonHelper::responseError(__('invalid_or_missing_channel'));
            }

            // Order only this channel's cart lines (plus legacy NULL-channel rows).
            $cartItems = Cart::select('carts.*', 'product_variants.name as product_name', 'products.sales_channel', 'products.product_type', 'products.is_prescription_required', 'stores.name as store_name')
                ->join('products', 'carts.product_id', '=', 'products.id')
                ->join('product_variants', 'carts.product_variant_id', '=', 'product_variants.id')
                ->leftJoin('stores', 'carts.store_id', '=', 'stores.id')
                ->where('user_id', '=', $user->id)
                ->where(function ($q) use ($channel) {
                    $q->where('carts.channel', $channel)->orWhereNull('carts.channel');
                })
                ->get();

            if ($cartItems->isEmpty()) {
                return CommonHelper::responseError(__('cart_is_empty'));
            }

            // Cart line -> resolved store (cart holds the correct per-channel store).
            $cartStoreByVariant = $cartItems->keyBy('product_variant_id');
            $cartStoreIds = array_values(array_unique(array_filter($cartItems->pluck('store_id')->all())));

            // Quick orders must be fulfilled by a single store.
            if ($channel === 'quick' && count($cartStoreIds) > 1) {
                return CommonHelper::responseError(__('quick_order_items_must_be_from_single_store'));
            }

            // Every item must be sellable on the requested channel.
            foreach ($cartItems as $ci) {
                $sc = $ci->sales_channel ?? 'both';
                if (!in_array($sc, [$channel, 'both'], true)) {
                    return CommonHelper::responseErrorWithData(__('item_not_available_for_this_channel'), $ci->product_name);
                }
            }

            // ---- Prescriptions (medical products) ----
            // Each medical item flagged prescription-required needs its own file, sent as
            // prescription[<product_variant_id>].
            $prescriptionFiles = $request->file('prescription') ?: [];
            $prescriptionPaths = [];
            foreach ($cartItems as $ci) {
                if ((int) ($ci->product_type ?? 0) !== 5) {
                    continue;
                }

                $file = $prescriptionFiles[$ci->product_variant_id] ?? null;

                if (!$file || !$file->isValid()) {
                    if ((int) ($ci->is_prescription_required ?? 0) === 1) {
                        return CommonHelper::responseErrorWithData(__('prescription_is_required_for_this_product'), $ci->product_name);
                    }
                    continue;
                }

                $prescriptionPaths[$ci->product_variant_id] = $file;
            }

            $address_id = $request->address_id ?? 0;
            $address = '';
            $mobile = '';
            $latitude = '';
            $longitude = '';

            $user_address = CommonHelper::getUserAddress($request->address_id);
            if (!empty($user_address)) {
                $addressData = CommonHelper::userAddressData($user_address);
                $mobile = $addressData['mobile'];
                $latitude = $user_address->latitude;   // local only (zone resolution)
                $longitude = $user_address->longitude;
            } else {
                return CommonHelper::responseError(__('something_is_missing_in_your_address'));
            }

            // Resolve the delivery zone once (reused for min-order, currency snapshot).
            $orderZone = CommonHelper::getDeliverableCity($latitude, $longitude, $channel);

            // Fulfilling store for this order/zone. Price is per-store (PVSS), so the
            // pricing store is the zone's active store, falling back to the cart line's
            // pinned store. Resolved once and reused for pricing + the item snapshot.
            $zoneStoreId = null;
            if ($orderZone) {
                $zoneStoreId = Store::where('zone_id', $orderZone->id)
                    ->where('status', Store::$statusActive)
                    ->value('id');
            }

            // Quick commerce can't place an order while the store is closed
            if ($channel === 'quick' && $zoneStoreId) {
                $orderStore = Store::find($zoneStoreId);
                if ($orderStore && !CommonHelper::isStoreOpenNow($orderStore)) {
                    return CommonHelper::responseError(__('store_is_closed'));
                }
            }
            $storeForVariant = function ($variant_id) use ($zoneStoreId, $cartStoreByVariant) {
                $row = $cartStoreByVariant[$variant_id] ?? null;
                return $zoneStoreId ?: (($row && !empty($row->store_id)) ? (int) $row->store_id : null);
            };

            $user_id = auth()->user()->id;
            $order_note = (isset($request->order_note) && !empty($request->order_note)) ? $request->order_note : "";
            $wallet_used = (isset($request->wallet_used) && !empty($request->wallet_used) == 'true') ? 'true' : 'false';

            // Items, quantities and subtotal are authoritative from the server cart.
            // Client-sent total / final_total / delivery_charge / surge / additional /
            // item lists are ignored to prevent price tampering.
            $cartData     = CommonHelper::getCartCount($user_id, $channel);
            $item_arr     = array_values(array_filter(explode(",", (string) ($cartData->product_variant_id ?? '')), fn($v) => $v !== ''));
            $quantity_arr = explode(",", (string) ($cartData->quantity ?? ''));
            $sub_total    = (float) ($cartData->total_amount ?? 0); // tax-included subtotal
            $total        = $sub_total;

            // Surge / additional charges are recomputed from the zone below.
            $surge_charges      = [];
            $additional_charges = [];

            $promo_code = "";
            $promo_discount = 0;
            $promo_code_id = 0;
            $cashback_amount = 0;
            $promoFreeDelivery = 0;

            if (isset($request->promocode_id) && $request->promocode_id && $request->promocode_id != "") {

                $code = PromoCode::find($request->promocode_id);

                if (empty($code)) {
                    return CommonHelper::responseError("Promo code not found!");
                }
                $promo = CommonHelper::validatePromoCode($user_id, $code->promo_code, $sub_total, [
                    'latitude'  => $latitude ?? null,
                    'longitude' => $longitude ?? null,
                    'channel'   => $channel ?? null,
                ]);

                if ($promo['is_applicable'] == 0) {
                    return CommonHelper::responseError($promo['message']);
                }

                if (isset($promo['promo_code_id']) && $request->promocode_id == $promo['promo_code_id']) {
                    // Wallet-type promo = cashback credited on delivery; it does NOT reduce the
                    // payable total. Instant-type reduces the total as before.
                    if (($promo['discount_apply_type'] ?? '') === 'wallet') {
                        $cashback_amount = $promo['discount'];
                        $promo_discount = 0;
                    } else {
                        $promo_discount = $promo['discount'];
                    }
                    $promo_code = $promo['promo_code'] . "(" . $promo['discount'] . ")";
                    $promo_code_id = $promo['promo_code_id'];
                    $promoFreeDelivery = (int) ($promo['free_delivery'] ?? 0);
                }
            }

            $wallet_balance = (isset($request->wallet_balance) && is_numeric($request->wallet_balance)) ? $request->wallet_balance : 0;
            $payment_method = $request->payment_method;

            $active_status = $payment_method == Transaction::$paymentTypeCod ? OrderStatusList::$received : OrderStatusList::$paymentPending;
            if ($payment_method == 'Wallet') {
                $active_status = OrderStatusList::$received;
            }

            // Status-history snapshot stored on each order_item (order_items.status).
            $status[] = array($active_status, date("d-m-Y h:i:sa"));

            if (empty($item_arr)) {
                return CommonHelper::responseError(__('cart_is_empty'));
            }

            // Map quantity to its variant id (cart order) so later loops over
            // resolved item_details (which may reorder) pick the right quantity.
            $qtyByVariant = [];
            foreach ($item_arr as $i => $vid) {
                $qtyByVariant[(int) $vid] = $quantity_arr[$i] ?? 1;
            }

            foreach ($item_arr as $key => $item) {
                $variant = ProductVariant::where("id", $item)->first();

                // Check if the variant exists
                if (empty($variant)) {
                    return CommonHelper::responseError(__('found_one_or_more_items_in_order_is_not_available_for_order'));
                }

                // Ensure the requested quantity is correctly retrieved
                $requested_qty = $quantity_arr[$key] ?? 1; // Default to 1 if missing

                // Stock is per-store (PVSS) — check against the cart line's store.
                $cartRow = $cartStoreByVariant[$item] ?? null;
                $check_store_id = $cartRow->store_id ?? null;

                $reservedForLine = (int) ($cartRow->qty ?? 0);

                // Check stock availability (store-wise)
                if (!ProductHelper::isItemAvailableWithStock(null, $item, $requested_qty, $check_store_id, $reservedForLine)) {
                    $available = ($check_store_id
                        ? ProductHelper::storeAvailableStock($item, $check_store_id)
                        : ProductHelper::totalStock($item)) + $reservedForLine;
                    return CommonHelper::responseError(__("Low stock: Only {$available} available for {$variant->product->name}"));
                }
            }

            $item_details = CommonHelper::getProductByVariantId($item_arr);

            // Price is per-store (PVSS): copy each line's fulfilling-store pricing onto
            // the in-memory variant so tax/subtotal/slab math reads the store price.
            foreach ($item_details as $it) {
                ProductHelper::applyStorePrice($it, $storeForVariant($it->id));
            }
            // Store id aligned to $item_arr order for the savings calc below.
            $store_arr = array_map(fn ($vid) => $storeForVariant((int) $vid), $item_arr);

            $totalTax = CommonHelper::calculateOrderTotalTax($item_details, $quantity_arr);
            $order_total_tax_amt = $totalTax['order_total_tax_amt'];
            $order_total_tax_per = $totalTax['order_total_tax_per'];

            // ====== GST CALCULATION (NEW) ======
            // Calculate GST breakdown (CGST, SGST, IGST)
            $gstCalculation = \App\Helpers\GSTHelper::calculateOrderGST(
                $item_details,
                $quantity_arr,
                $addressData,
                $promo_discount
            );
            
            $order_cgst_amount = $gstCalculation['gst']['cgst'] ?? 0;
            $order_sgst_amount = $gstCalculation['gst']['sgst'] ?? 0;
            $order_igst_amount = $gstCalculation['gst']['igst'] ?? 0;
            $is_intra_state = $gstCalculation['gst']['is_intra_state'] ?? true;
            $company_state = $gstCalculation['gst']['company_state'] ?? '';
            $customer_state = $gstCalculation['gst']['customer_state'] ?? '';
            
            // Log GST data from frontend for audit trail
            $frontend_gst_inclusive = $request->input('gst_inclusive');
            $frontend_cgst_amount = (float) ($request->input('cgst_amount') ?? 0);
            $frontend_sgst_amount = (float) ($request->input('sgst_amount') ?? 0);
            $frontend_igst_amount = (float) ($request->input('igst_amount') ?? 0);
            $frontend_total_gst = (float) ($request->input('total_gst_amount') ?? 0);
            $frontend_final_price = (float) ($request->input('final_price_with_gst') ?? 0);
            
            \Log::debug('[GST_ORDER_PLACEMENT]', [
                'user_id' => $user_id,
                'backend_calculation' => [
                    'cgst' => $order_cgst_amount,
                    'sgst' => $order_sgst_amount,
                    'igst' => $order_igst_amount,
                    'is_intra_state' => $is_intra_state,
                    'company_state' => $company_state,
                    'customer_state' => $customer_state,
                    'total_gst' => ($order_cgst_amount + $order_sgst_amount + $order_igst_amount),
                ],
                'frontend_sent' => [
                    'gst_inclusive' => $frontend_gst_inclusive,
                    'cgst_amount' => $frontend_cgst_amount,
                    'sgst_amount' => $frontend_sgst_amount,
                    'igst_amount' => $frontend_igst_amount,
                    'total_gst' => $frontend_total_gst,
                    'final_price_with_gst' => $frontend_final_price,
                ],
            ]);
            // ===================================

            // Savings vs base price (MRP) — same calc the cart exposes as saved_amount.
            $amountTotals = CommonHelper::calculateTotalAmount($item_arr, $quantity_arr, $store_arr);
            $saved_amount = max(0, $amountTotals['save_price'] - $amountTotals['total_amount']);

            $generate_otp = Setting::get_value("generate_otp");
            if ($generate_otp == 1) {
                $otp_number = mt_rand(100000, 999999);
            } else {
                $otp_number = 0;
            }

            /* check for wallet balance — wallets are country-wise (user_wallets),
               the order's country comes from the delivery location's zone. */
            $orderCountryId = $orderZone ? (int) ($orderZone->country_id ?? 0) : 0;
            if ($wallet_used == 'true') {
                $user_wallet_balance = CommonHelper::getUserWalletBalance(auth()->user()->id, $orderCountryId ?: null);
                if ($user_wallet_balance < $wallet_balance) {
                    return CommonHelper::responseError('insufficient_wallet_balance');
                }
            }

            /* Recompute delivery + surge + additional charges from the zone — never
               trust client amounts. free_delivery promo zeroes only delivery_charge. */
            $deliveryResp = CommonHelper::getAllDeliveryCharge($latitude, $longitude, $cartStoreIds, $sub_total, $channel);
            if (($deliveryResp['status'] ?? 0) == 0 && !isDemoMode()) {
                return CommonHelper::responseError($deliveryResp['message'] ?? __('sorry_we_are_not_delivering_on_selected_address'));
            }
            $deliveryData       = $deliveryResp['data'] ?? [];
            $delivery_charge    = $promoFreeDelivery ? 0 : (float) ($deliveryData['total_delivery_charge'] ?? 0);
            $surge_total        = (float) ($deliveryData['surge_total'] ?? 0);
            $additional_total   = (float) ($deliveryData['additional_total'] ?? 0);
            $surge_charges      = $deliveryData['surge_charges'] ?? [];
            $additional_charges = $deliveryData['additional_charges'] ?? [];

            // Gross order value (before wallet).
            $gross_total = $sub_total + $delivery_charge + $surge_total + $additional_total - $promo_discount;
            $gross_total = $gross_total < 0 ? 0 : $gross_total;

            // If frontend sent the final GST-inclusive price, use it (ensures parity with what customer saw)
            $frontend_final_price = (float) ($request->input('final_price_with_gst') ?? 0);
            if ($frontend_final_price > 0) {
                // Use frontend amount as the authoritative total (includes GST)
                $gross_total_with_gst = $frontend_final_price;
                
                \Log::info('[ORDER_FINAL_PRICE]', [
                    'backend_calculated_gross_total' => $gross_total,
                    'frontend_sent_final_price_with_gst' => $frontend_final_price,
                    'using_frontend_price' => true,
                ]);
            } else {
                // Fallback: assume gross_total already includes GST from calculateTotalAmount
                $gross_total_with_gst = $gross_total;
            }

            // Wallet amount actually applied (never more than the order value).
            $walletvalue = ($wallet_used == 'true') ? min((float) $wallet_balance, (float) $gross_total_with_gst) : 0;

            $final_total = round(max(0, $gross_total_with_gst - $walletvalue), 2);

            // Wallet covers the entire order -> paid at placement (like a Wallet
            // payment) even if an online method was selected. Mark received + finalize now
            // (deduct wallet, notify, no gateway) instead of leaving it payment-pending.
            $walletFullyPays = ($wallet_used == 'true' && (float) $gross_total > 0 && $final_total <= 0);
            if ($walletFullyPays) {
                $active_status = OrderStatusList::$received;
            }
            // Payments settled at placement (no gateway round-trip needed).
            $immediatePayment = ($payment_method == Transaction::$paymentTypeCod
                || $payment_method == Transaction::$paymentTypeWallet
                || $walletFullyPays);

            /* Minimum order amount: quick orders honour the delivery zone's
               minimum_order_amount (when set) — checked against the gross order
               value (wallet portion included). */
            if ($channel === 'quick') {
                $zoneMinOrder = $orderZone ? (float) ($orderZone->minimum_order_amount ?? 0) : 0;
                if ($zoneMinOrder > 0 && $gross_total < $zoneMinOrder) {
                    return CommonHelper::responseError("Minimum order amount is " . $zoneMinOrder . ".");
                }
            }

            $store_ids = array_values(array_unique(array_filter(array_column($item_details->toArray(), "store_id"))));
            if (empty($store_ids)) {
                return CommonHelper::responseError(__('found_one_or_more_items_in_order_is_not_available_for_order'));
            }
            if (!CommonHelper::isDeliverableOrder($latitude, $longitude, $store_ids[0], $channel) && !isDemoMode()) {
                return CommonHelper::responseError(__('sorry_we_are_not_delivering_on_selected_address'));
            }

            /* insert data into order table */
            DB::beginTransaction();
            try {

                $order = new Order();
                $order->user_id = $user_id;
                $order->delivery_boy_id = 0;
                $order->transaction_id = 0;
                $order->otp = ($channel === 'ecommerce') ? 0 : $otp_number;
                $order->mobile = $mobile;
                $order->order_note = $order_note;
                $order->total = $total;
                $order->remaining_total = $total;
                $order->delivery_charge = $delivery_charge;
                $order->tax_amount = $order_total_tax_amt;
                $order->tax_percentage = $order_total_tax_per;
                // Store GST breakdown
                $order->cgst_amount = $order_cgst_amount;
                $order->sgst_amount = $order_sgst_amount;
                $order->igst_amount = $order_igst_amount;
                $order->is_intra_state = $is_intra_state;
                $order->company_state = $company_state;
                $order->customer_state = $customer_state;
                $order->company_gstin = \App\Models\Setting::get_value('company_gstin') ?? '';
                $order->company_pan = \App\Models\Setting::get_value('company_pan') ?? '';
                // End GST
                $order->wallet_balance = $walletvalue;
                $order->paid_wallet = $walletvalue;
                $order->promo_code_id = $promo_code_id;
                $order->promo_code = $promo_code;
                $order->promo_discount = $promo_discount;
                $order->cashback_amount = $cashback_amount;
                $order->saved_amount = $saved_amount;
                $order->final_total = $final_total;
                $order->remaining_final = $final_total;
                $order->payment_method = $payment_method;
                $order->address = $addressData; // JSON (model array cast)
                $order->active_status = $active_status;
                $order->channel = $channel;
                // Country/zone stamp + currency snapshot (currency is country-wise now, so
                // history stays correct even if the country's currency is later edited).
                $orderCountry = $orderZone ? $orderZone->country : null;
                $order->country_id = $orderCountry->id ?? null;
                $order->zone_id = $orderZone->id ?? null;
                $order->currency = $orderCountry->currency ?? null;
                $order->currency_code = $orderCountry->currency_code ?? null;
                $order->address_id = $address_id;
                // additional_charges is array-cast on the model (auto-encoded);
                // surge_charges has no cast, so it is encoded manually.
                $order->additional_charges = $additional_charges;
                $order->surge_charges = json_encode($surge_charges);
                $order->save();

                $order_id = $order->id;
                if ($order_id == "") {
                    return CommonHelper::responseError(__('order_can_not_place_due_to_some_reason_try_again_after_some_time'));
                }

                $order->order_number = CommonHelper::formatNumber(Setting::get_value('order_prefix') ?: 'ORD-', $order_id);
                $orderInvoiceNumber = CommonHelper::formatNumber(Setting::get_value('invoice_prefix') ?: 'INV-', $order_id);
                $order->invoice_number = $orderInvoiceNumber;
                $order->saveQuietly();
                /* Pre-pass: compute each line's amounts + sub_total (needed to split
                   the order's charges proportionally for ecommerce). Price/cost are
                   per-store (PVSS) and already copied onto $item_details above via the
                   line's fulfilling store; that store is also recorded on the item. */
                $lines = [];
                foreach ($item_details as $key => $item) {
                    $product_variant_id = $item->id;
                    $lineStoreId = $storeForVariant((int) $product_variant_id);
                    // Stock was reserved against the cart line's store at add-to-cart;
                    // the commit must release from that same store.
                    $cartRow = $cartStoreByVariant[$product_variant_id] ?? null;
                    $cartStoreId = ($cartRow && !empty($cartRow->store_id)) ? (int) $cartRow->store_id : $lineStoreId;
                    $price = $item->price;
                    $purchase_price = (float) ($item->purchase_price ?? 0); // cost snapshot
                    $quantity = $qtyByVariant[(int) $product_variant_id] ?? ($quantity_arr[$key] ?? 1);
                    // Slab-aware effective pre-tax unit price; recorded as discounted_price
                    // when it beats the base so tax/subtotal math reflects the slab.
                    $effectiveUnit = ProductHelper::slabUnitPrice($item, (int) $quantity);
                    $discounted_price = ($effectiveUnit < (float) $price) ? $effectiveUnit : 0;
                    
                    // GST Logic: Check if product has GST inclusive flag and get GST rate
                    $product = DB::table('products')->where('id', $item->product_id)->first(['gst_rate', 'gst_inclusive']);
                    $gstRate = (float) ($product->gst_rate ?? 0);
                    $gstInclusive = (bool) ($product->gst_inclusive ?? false);
                    $tax_percentage = $gstRate; // Use GST rate, not old tax_percentage
                    
                    // Calculate tax amount based on GST type
                    if ($gstInclusive) {
                        // GST Inclusive: Tax is already included in price, store 0 as tax_amt
                        $tax_amt = 0;
                    } else {
                        // GST Exclusive: Calculate tax on top of price
                        $tax_amt = $discounted_price != 0 ? (($tax_percentage / 100) * $discounted_price) : (($tax_percentage / 100) * $price);
                    }
                    
                    // Calculate item subtotal based on GST type
                    if ($gstInclusive) {
                        // GST Inclusive: Price already includes GST, don't add it again
                        $item_sub_total = $discounted_price != 0
                            ? ($discounted_price) * $quantity
                            : ($price) * $quantity;
                    } else {
                        // GST Exclusive: Add GST on top of price
                        $item_sub_total = $discounted_price != 0
                            ? ($discounted_price + ($tax_percentage / 100) * $discounted_price) * $quantity
                            : ($price + ($tax_percentage / 100) * $price) * $quantity;
                    }
                    $lines[] = [
                        'variant_id'   => $product_variant_id,
                        'product_name' => $item->product_name,
                        'hsn_code'     => $item->hsn_code ?? null,
                        'price'        => $price,
                        'purchase_price' => $purchase_price,
                        'quantity'     => $quantity,
                        'discounted'   => $discounted_price,
                        'tax_pct'      => $tax_percentage,
                        'tax_amt'      => $tax_amt,
                        'sub_total'    => $item_sub_total,
                        'gst_inclusive' => $gstInclusive,
                        'cart_store'   => $cartStoreId,
                        'store_id'     => $lineStoreId ?? "",
                        // Snapshot the product's cancel/return policy at purchase time so later
                        // product edits don't retroactively change this order. till_status is
                        // resolved for the order's channel.
                        'cancelable_status' => (int) ($item->cancelable_status ?? 0),
                        'till_status'       => ($channel === 'quick' ? $item->till_status_quick : $item->till_status_ecommerce) ?? 0,
                        'return_status'     => (int) ($item->return_status ?? 0),
                        'return_days'       => (int) ($item->return_days ?? 0),
                    ];
                }

                // Ecommerce orders are item-wise: split each order-level charge across
                // items by sub_total (last item absorbs rounding). Quick stays order-wise.
                $isEcommerce = ($channel === 'ecommerce');
                $weights = array_column($lines, 'sub_total');
                $deliveryShares = $promoShares = [];
                $addlPerItem = $surgePerItem = array_fill(0, count($lines), []);
                $walletShares = [];
                if ($isEcommerce) {
                    $deliveryShares = CommonHelper::splitByWeight($weights, (float) $delivery_charge);
                    $promoShares    = CommonHelper::splitByWeight($weights, (float) $promo_discount);
                    $walletShares   = CommonHelper::splitByWeight($weights, (float) $walletvalue);
                    foreach ((array) $additional_charges as $c) {
                        $amts = CommonHelper::splitByWeight($weights, (float) ($c['amount'] ?? 0));
                        foreach ($amts as $i => $a) {
                            if ($a != 0) {
                                $addlPerItem[$i][] = ['name' => $c['name'] ?? '', 'amount' => $a, 'is_refundable' => (bool) ($c['is_refundable'] ?? false)];
                            }
                        }
                    }
                    foreach ((array) $surge_charges as $s) {
                        $amts = CommonHelper::splitByWeight($weights, (float) ($s['charge'] ?? 0));
                        foreach ($amts as $i => $a) {
                            if ($a != 0) {
                                $surgePerItem[$i][] = ['label' => $s['label'] ?? '', 'charge' => $a, 'is_refundable' => (bool) ($s['is_refundable'] ?? false)];
                            }
                        }
                    }
                }

                $order_item_status = json_encode($status);
                foreach ($lines as $i => $ln) {
                    $order_item = new OrderItem();
                    $order_item->user_id = $user_id;
                    $order_item->order_id = $order_id;
                    $order_item->invoice_number = $orderInvoiceNumber . '-' . ($i + 1);
                    $order_item->product_name = $ln['product_name'];
                    $order_item->hsn_code = $ln['hsn_code'];
                    
                    // Store per-item GST breakdown from calculation
                    if (isset($gstCalculation['item_breakdown'][$i])) {
                        $itemGst = $gstCalculation['item_breakdown'][$i];
                        $order_item->cgst_amount = $itemGst['cgst'] ?? 0;
                        $order_item->sgst_amount = $itemGst['sgst'] ?? 0;
                        $order_item->igst_amount = $itemGst['igst'] ?? 0;
                        $order_item->gst_rate = $itemGst['gst_rate'] ?? 0;
                    }
                    // Save GST inclusive flag from the line
                    $order_item->gst_inclusive = (bool) ($ln['gst_inclusive'] ?? false);
                    // End per-item GST
                    
                    // Snapshot the variant's attribute name/value pairs.
                    $order_item->variant_attributes = ProductVariantAttributeValue::with(['attribute', 'attributeValue'])
                        ->where('product_variant_id', $ln['variant_id'])->get()
                        ->map(fn($av) => [
                            'name'  => $av->attribute ? (string) $av->attribute->name : '',
                            'value' => $av->attributeValue ? (string) $av->attributeValue->value : '',
                        ])
                        ->filter(fn($a) => $a['name'] !== '' || $a['value'] !== '')
                        ->values()->all();
                    $order_item->product_variant_id = $ln['variant_id'];
                    // Snapshot this item's prescription (medical items only; validated above).
                    $order_item->prescription = isset($prescriptionPaths[$ln['variant_id']])
                        ? CommonHelper::uploadFile($prescriptionPaths[$ln['variant_id']], null, 'prescription')
                        : null;
                    $order_item->quantity = $ln['quantity'];
                    $order_item->price = $ln['price'];
                    $order_item->discounted_price = $ln['discounted'];
                    $order_item->purchase_price = $ln['purchase_price'];
                    $order_item->tax_amount = $ln['tax_amt'];
                    $order_item->tax_percentage = $ln['tax_pct'];
                    $order_item->sub_total = $ln['sub_total'];
                    $order_item->status = $order_item_status;
                    $order_item->active_status = $active_status;
                    $order_item->store_id = $ln['store_id'];
                    // Frozen cancel/return policy (snapshot at placement).
                    $order_item->cancelable_status = $ln['cancelable_status'];
                    $order_item->till_status = $ln['till_status'];
                    $order_item->return_status = $ln['return_status'];
                    $order_item->return_days = $ln['return_days'];

                    if ($isEcommerce) {
                        $itemDelivery   = $deliveryShares[$i] ?? 0;
                        $itemAddl       = $addlPerItem[$i] ?? [];
                        $itemSurge      = $surgePerItem[$i] ?? [];
                        $itemAddlTotal  = array_sum(array_column($itemAddl, 'amount'));
                        $itemSurgeTotal = array_sum(array_column($itemSurge, 'charge'));
                        $itemPromo      = $promoShares[$i] ?? 0;
                        $itemWallet     = $walletShares[$i] ?? 0;
                        $itemFinal = round(max(0, $ln['sub_total'] + $itemDelivery + $itemAddlTotal + $itemSurgeTotal - $itemPromo - $itemWallet), 2);
                        $order_item->delivery_charge    = $itemDelivery;
                        $order_item->additional_charges = $itemAddl;
                        $order_item->surge_charges      = $itemSurge;
                        $order_item->promo_discount     = $itemPromo;
                        $order_item->wallet_balance     = $itemWallet;
                        $order_item->final_total        = $itemFinal;
                        // Per-item OTP for ecommerce (each item has its own delivery boy).
                        $order_item->otp = ($generate_otp == 1) ? mt_rand(100000, 999999) : 0;
                    } else {
                        // Quick orders: OTP lives on orders.otp — items always 0.
                        $order_item->otp = 0;
                    }
                    $order_item->save();

                    // Ecommerce is item-wise: seed each item's status history so its
                    // timeline isn't empty at placement.
                    if ($isEcommerce) {
                        CommonHelper::setOrderStatus([
                            'order_id'      => $order_id,
                            'order_item_id' => $order_item->id,
                            'status'        => $active_status,
                            'created_by'    => $user_id,
                            'user_type'     => OrderStatus::$userTypeUser,
                        ]);
                    }

                    // Stock reservation is committed (reserved -= qty) only when payment
                    // is settled at placement (COD / Wallet). For online gateway orders the
                    // stock stays RESERVED until the payment is confirmed — committed then in
                    // the success handler, or released if the pending order is abandoned.
                    if ($immediatePayment) {
                        ProductHelper::commitReservedStock($ln['variant_id'], $ln['cart_store'], $ln['quantity']);
                    }
                }

                // Cart clearing is payment-timing aware:
                //  - COD / Wallet / wallet-fully-paid (immediate): consume the cart now.
                //  - Online gateway: KEEP the cart until the payment is confirmed
                //    (cleared in addTransaction / gateway webhooks when the order is
                //    marked received) so the customer can retry a failed payment.
                if ($immediatePayment) {
                    Cart::where('user_id', $user_id)
                        ->whereIn('product_variant_id', $item_details->pluck('id')->all())
                        ->where(function ($q) use ($channel) {
                            $q->where('channel', $channel)->orWhereNull('channel');
                        })
                        ->delete();
                }

                if ($wallet_used == 'true' && $immediatePayment) {
                    /* deduct the balance & set the wallet transaction for payments settled at placement
                       (COD/Wallet, or online fully covered by wallet). For online payments still awaiting
                       a gateway charge, wallet is deducted after confirmation in addTransaction/webhook.
                       Wallet is country-wise — deduct from the order's country wallet. */
                    $walletCountryId = $order->country_id;
                    $curBal = CommonHelper::getUserWalletBalance($user_id, $walletCountryId);
                    $new_balance = $curBal < $walletvalue ? 0 : $curBal - $walletvalue;
                    CommonHelper::updateUserWalletBalance($new_balance, $user_id, $walletCountryId);
                    CommonHelper::addWalletTransaction($order_id, 0, $user_id, 'debit', $walletvalue, 'wallet_used_against_order_placement', 1, $payment_method);
                }

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
            if (!empty($order) && $immediatePayment) {
                try {
                    dispatch(function () use ($order) {
                        //push notification
                        CommonHelper::sendNotificationOrderStatus($order);
                        // Send notifications to super admin (role_id=1) and sellers (role_id=3) whose items are in the order
                        CommonHelper::sendOrderNotificationsToAdmins($order, 'new_order', $order->delivery_boy_id ?? null);
                    })->afterResponse();
                } catch (\Exception $e) {
                    Log::error("Place orderNotification error :", [$e->getMessage()]);
                }
                try {

                    Log::info("Place order send mail :", [$order]);
                    dispatch(new SendEmailJob($order))->afterResponse();
                } catch (\Exception $e) {
                    Log::error("Place order Send mail error :", [$e->getMessage()]);
                }

                //Place Order Send SMS
                try {
                    CommonHelper::sendSmsOrderStatus($order, $order->active_status);
                } catch (\Exception $e) {
                    Log::error("Place order SMS error :", [$e->getMessage()]);
                }
            }

            if ($immediatePayment) {
                $order_status = array();
                $order_status['order_id'] = $order->id;
                $order_status['order_item_id'] = 0;
                $order_status['status'] = OrderStatusList::$received;
                $order_status['created_by'] = $user_id;
                $order_status['user_type'] = OrderStatus::$userTypeUser;
                CommonHelper::setOrderStatus($order_status);
            }

            $responseData = [
                'order_id'      => $order->id,
                'order_item_id' => optional($order->items()->orderBy('id')->first())->id,
            ];
            return CommonHelper::responseSuccessWithData(__('order_placed_successfully'), $responseData);
        } catch (\Exception $e) {
            Log::error("Place order error :", [$e->getMessage()]);
            return CommonHelper::responseError(__('could_not_place_order_try_again'));
        }
    }

    public function deletePaymentPendingOrder(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required'
        ]);
        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        $order = Order::find($request->order_id);
        $user = auth()->user();
        $user_wallet_balance = $user->balance;

        if (empty($order)) {
            return CommonHelper::responseError("Order Not found!");
        }

        if ($order->active_status != OrderStatusList::$paymentPending) {
            $statusName = OrderStatusList::where('id', $order->active_status)->value('status');
            return CommonHelper::responseError("Now you order status is " . $statusName);
        }

        DB::beginTransaction();
        try {
            // Retrieve the order items before deletion
            $orderItems = OrderItem::where('order_id', $request->order_id)->get();

            // Delete the order items
            OrderItem::where('order_id', $request->order_id)->delete();

            // Payment-pending orders are ONLINE + unpaid, so their stock is still
            // RESERVED (never committed). Release it: reserved -= qty, available += qty.
            foreach ($orderItems as $item) {
                ProductHelper::releaseStock($item->product_variant_id, $item->store_id, (int) $item->quantity);
            }
            $order->delete();

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::info("Error : " . $e->getMessage());
            throw $e;
            return CommonHelper::responseError(__('something_went_wrong'));
        }
        return CommonHelper::responseSuccess(__('order_deleted_successfully'));
    }

    public function initiateTransaction(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'payment_method' => 'required',
            'type' => 'required|in:order,wallet',
            'order_id' => 'required_if:type,order',
            'wallet_amount' => 'required_if:type,wallet',
        ]);

        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }
        // Gateways + currency are zone-wise. For an order, use the order's zone; for a
        // wallet recharge (no order) the app must send the current location.
        $channel = strtolower(trim((string) $request->header('channel')));
        $channel = in_array($channel, ['quick', 'ecommerce'], true) ? $channel : null;
        if ($request->type == 'order') {
            $order = Order::with('user')->where('id', $request->order_id)
                ->first();
            if (!$order) {
                return CommonHelper::responseError("Order not found!");
            }
            $zone = CommonHelper::resolveOrderZone($order);
        } else {
            if (!$request->filled('latitude') || !$request->filled('longitude')) {
                return CommonHelper::responseError(__('location_required_for_wallet_recharge'));
            }
            $zone = CommonHelper::getDeliverableCity($request->latitude, $request->longitude, $channel);
        }
        if (!$zone) {
            return CommonHelper::responseError(__('not_deliverable_to_this_location'));
        }
        $gateways     = CommonHelper::countryPaymentGateways($zone?->country);
        $currencyCode = $zone->currency_code ?: 'INR';
        // Country the payment is charged in (recharge credits this country's wallet).
        $paymentCountryId = $zone->country_id ?? null;

        $out['payment_method'] = $request->payment_method;

        $transaction_id = "";

        if ($request->payment_method == "Razorpay") {

            Log::error("payment_method = " . $request->payment_method);

            $transaction_id = TransactionHelper::createOrderonRazorpay($request->type, $request->order_id ?? 0, $request->wallet_amount ?? 0, $gateways, $currencyCode);
            if ($transaction_id == "") {
                return CommonHelper::responseError("Error while communicating with razorpay server");
            }
        } else if ($request->payment_method == "Paypal") {

            $user_id = auth()->user()->id;
            if ($request->type == 'order') {
                $order_id = $request->order_id;
                $order = Order::where('id', $order_id)->first();

                if (!empty($order)) {
                    if ($request->request_from == 'website') {
                        $out['paypal_redirect_url'] = url('customer/paypal_payment_url?user_id=' . $user_id . '&order_id=' . $order_id . '&type=order&request_from=website');
                    } else {
                        $out['paypal_redirect_url'] = url('customer/paypal_payment_url?user_id=' . $user_id . '&order_id=' . $order_id . '&type=order');
                    }
                }
            } elseif ($request->type == 'wallet') {
                // Carry the current-location country so the PayPal IPN credits its wallet.
                $countryParam = $paymentCountryId ? '&country_id=' . $paymentCountryId : '';
                $locParam = '&latitude=' . urlencode((string) $request->latitude) . '&longitude=' . urlencode((string) $request->longitude);
                if ($channel) {
                    $locParam .= '&channel=' . urlencode($channel);
                }
                if ($request->request_from == 'website') {
                    $out['paypal_redirect_url'] = url('customer/paypal_payment_url?user_id=' . $user_id . '&wallet_amount=' . $request->wallet_amount . '&type=wallet&request_from=website' . $countryParam . $locParam);
                } else {
                    $out['paypal_redirect_url'] = url('customer/paypal_payment_url?user_id=' . $user_id . '&wallet_amount=' . $request->wallet_amount . '&type=wallet' . $countryParam . $locParam);
                }
            }
        } else if ($request->payment_method == "Stripe") {

            Log::error("payment_method = " . $request->payment_method);

            if ($request->type == 'order') {
                $order_id = $request->order_id;
                $order = Order::where('id', $order_id)->first();

                if (!empty($order)) {
                    $response = TransactionHelper::createOrderOnStripe($order->final_total, 'order', $order->id, $gateways, $paymentCountryId);
                }
            } elseif ($request->type == 'wallet') {
                $response = TransactionHelper::createOrderOnStripe($request->wallet_amount, 'wallet', 0, $gateways, $paymentCountryId);
            }

            if ($response == "") {
                return CommonHelper::responseError("Error while communicating with Stripe server");
            }
            $out = $response->toArray();
        } else  if ($request->payment_method == "Midtrans") {
            $midtrans_redirect_url = TransactionHelper::createOrderonMidtrans($request->type, $request->order_id ?? 0, $request->wallet_amount ?? 0, $gateways, $paymentCountryId);
            if ($midtrans_redirect_url == "") {
                return CommonHelper::responseError("Error while communicating with Midtrans server");
            }

            return CommonHelper::responseWithData($midtrans_redirect_url);
        } else  if ($request->payment_method == "Phonepe") {
            $phonepay_data = TransactionHelper::createOrderonPhonepe($request->type, $request->order_id ?? 0, $request->wallet_amount ?? 0, $gateways, $paymentCountryId);
            if ($phonepay_data == "") {
                return CommonHelper::responseError("Error while communicating with Phonepe server");
            }
            return $phonepay_data;
        } else  if ($request->payment_method == "Cashfree") {
            $cashfree_redirect_url = TransactionHelper::createOrderonCashfree($request->type, $request->order_id ?? 0, $request->wallet_amount ?? 0, $gateways, $currencyCode, $paymentCountryId);
            if ($cashfree_redirect_url == "") {
                return CommonHelper::responseError("Error while communicating with Cashfree server");
            }
            return CommonHelper::responseWithData($cashfree_redirect_url);
        } else  if ($request->payment_method == "Paytabs") {
            $paytabs_redirect_url = TransactionHelper::createOrderonPaytabs($request->type, $request->order_id ?? 0, $request->wallet_amount ?? 0, $gateways, $currencyCode, $paymentCountryId);

            if ($paytabs_redirect_url == "") {
                return CommonHelper::responseError("Error while communicating with Paytabs server");
            }
            return CommonHelper::responseWithData($paytabs_redirect_url);
        } else {
            return CommonHelper::responseError("Invalid payment methods.");
        }
        if ($request->type == 'order') {
            $order->payment_method = $request->payment_method;
            $order->save();
        }

        if ($transaction_id != "") {
            $out['transaction_id'] = $transaction_id;
        }
        return CommonHelper::responseWithData($out);
    }

    /*Paypal Start*/
    public function paypalPaymentUrl(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required',
            'type' => 'required|in:order,wallet',
            'order_id' => 'required_if:type,order',
            'wallet_amount' => 'required_if:type,wallet',
        ]);

        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        $app_name = Setting::get_value('app_name');
        $user = User::where('id', $request->user_id)->first();
        if ($request->type == 'order') {
            $order = Order::with('items')->where('id', $request->order_id)->first();
            $order_amount = $order->final_total;
            $order_id = $order->id;
            $zone = CommonHelper::resolveOrderZone($order);
        } else {
            $order_amount = $request->wallet_amount;
            $walletChannel = strtolower(trim((string) ($request->header('channel') ?: $request->input('channel', ''))));
            $walletChannel = in_array($walletChannel, ['quick', 'ecommerce'], true) ? $walletChannel : null;
            $zone = ($request->filled('latitude') && $request->filled('longitude'))
                ? CommonHelper::getDeliverableCity($request->latitude, $request->longitude, $walletChannel)
                : null;
            // Tag the recharge with the current-location country (from initiation, or resolved
            // here) so the IPN credits that country's wallet.
            $rechargeCountryId = $request->filled('country_id') ? (int) $request->country_id : ($zone->country_id ?? null);
            $order_id = 'wallet_recharge-' . $user->id . CommonHelper::rechargeCountrySuffix($rechargeCountryId);
        }
        $gateways = $zone ? CommonHelper::countryPaymentGateways($zone?->country) : [];

        if ($order_amount) {

            header("Content-Type: html");

            $data['user'] = $user;

            $data['payment_type'] = "paypal";

            $websiteUrl = Setting::where('variable', 'website_url')->value('value');
            $websiteUrl = trim($websiteUrl, '/');
            $returnURL = $request->request_from == 'website' ? url($websiteUrl . '/web-payment-status?amount=' . $order_amount . '&status=pending&type=wallet') : url('customer/paypal_redirect/pending');
            if ($request->type == 'order') {
                if ($request->request_from == 'website') {
                    $returnURL = url($websiteUrl . '/web-payment-status?amount=' . $order_amount . '&status=pending&type=order&order_id=' . $order_id);
                } else {
                    $returnURL = url('customer/paypal_redirect/pending');
                }
            } elseif ($request->type == 'wallet') {
                if ($request->request_from == 'website') {
                    $returnURL = url($websiteUrl . '/web-payment-status?amount=' . $order_amount . '&status=pending&type=wallet');
                } else {
                    $returnURL = url('customer/paypal_redirect/pending');
                }
            }
          
            $statusQuery = '/web-payment-status?amount=' . $order_amount . '&type=' . $request->type
                . ($request->type == 'order' ? '&order_id=' . $order_id : '');
            $cancelURL = $request->request_from == 'website'
                ? url($websiteUrl . $statusQuery . '&status=fail')
                : url('customer/paypal_redirect/fail');
            $pendingURL = $request->request_from == 'website'
                ? url($websiteUrl . $statusQuery . '&status=pending')
                : url('customer/paypal_redirect/pending');
            $notifyURL = url('ipn');
            // Get current user ID from the session
            $userID = $data['user']['id'];
            //$order_id = $order_id;
            $payeremail = $data['user']['email'];
            // $userID = $data['user']->id;

            $paypal = new Paypal($gateways);
            // Add fields to paypal form
            $paypal->add_field('return', $returnURL);
            $paypal->add_field('pending', $pendingURL);
            $paypal->add_field('cancel_return', $cancelURL);
            $paypal->add_field('notify_url', $notifyURL);
            $paypal->add_field('item_name', $app_name);
            $paypal->add_field('custom', $userID . '|' . $payeremail);
            $paypal->add_field('item_number', $order_id);
            $paypal->add_field('amount', $order_amount);

            // Render paypal form
            $paypal->paypal_auto_form();
        }
    }

    public function paypalRedirect(Request $request)
    {
        $paypalInfo = $request->all();
        $website_url = Setting::where('variable', 'website_url')->value('value');

        $order_status = Transaction::$statusFailed;
        if (!empty($paypalInfo) && isset($paypalInfo['payment_status']) && strtolower($paypalInfo['payment_status']) == "completed") {
            $response['error'] = false;
            $response['message'] = "Payment Completed Successfully";
            $response['data'] = $paypalInfo;
            $order_status = Transaction::$statusSuccess;
        } elseif (!empty($paypalInfo) && isset($paypalInfo['payment_status']) && strtolower($paypalInfo['payment_status']) == "authorized") {
            $response['error'] = false;
            $response['message'] = "Your payment is has been Authorized successfully. We will capture your transaction within 30 minutes, once we process your order. After successful capture coins wil be credited automatically.";
            $response['data'] = $paypalInfo;
            $order_status = Transaction::$statusSuccess;
        } elseif (!empty($paypalInfo) && isset($paypalInfo['payment_status']) && strtolower($paypalInfo['payment_status']) == "pending") {
            $response['error'] = false;
            $response['message'] = "Your payment is pending and is under process. We will notify you once the status is updated.";
            $response['data'] = $paypalInfo;
        } else {
            $response['error'] = true;
            $response['message'] = "Payment Cancelled / Declined ";
            $response['data'] = (isset($paypalInfo)) ? $paypalInfo : "";
        }

        echo "<html>
        <body>
        Redirecting...!
        </body>
        <script>
            //const parentOrigin = window.opener.location.origin;
            const parentOrigin = '" . $website_url . "';
            console.log('Parent origin:', parentOrigin);
            console.log('started')
            window.addEventListener('load', function(){
            console.log('loaded')
            window.opener.postMessage('" . $order_status . "',parentOrigin);
            window.close();
            });
        </script>
        </html>";
    }

    /*Paypal End*/

    public function addTransaction(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:order,wallet',
            'order_id' => 'required_if:type,order',
            'wallet_amount' => 'required_if:type,wallet',
            'latitude' => 'required_if:type,wallet',
            'longitude' => 'required_if:type,wallet',
            'device_type' => 'required',
            'app_version' => 'required',
            'payment_method' => 'required', // Razorpay / Paystack
            'transaction_id' => 'required',
        ]);

        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }
        $user = auth()->user();
        if ($request->type == 'order') {
            $order = Order::withTrashed()->where('id', $request->order_id)->first();
            if (!$order) {
                return CommonHelper::responseError("Invalid Order Id");
            }
        }

        // Gateway verification creds come from the zone (order's zone, or the request
        // location for a wallet recharge).
        if ($request->type == 'order') {
            $zone = CommonHelper::resolveOrderZone($order);
        } else {
            $zone = CommonHelper::getDeliverableCity($request->latitude, $request->longitude, strtolower(trim((string) $request->header('channel'))) ?: null);
            if (!$zone) {
                return CommonHelper::responseError(__('not_deliverable_to_this_location'));
            }
        }
        $gateways = $zone ? CommonHelper::countryPaymentGateways($zone?->country) : [];
        // Country the recharge credits (current location).
        $rechargeCountryId = ($request->type == 'wallet') ? ($zone->country_id ?? null) : null;

        if ($request->device_type) {
            $app_usage = array();
            $app_usage['device_type'] = $request->device_type;
            $app_usage['app_version'] = $request->app_version;

            if ($request->type == 'order') {
                $app_usage['order_id'] = $order->id ?? null;
            } else {
                $app_usage['order_id'] = 'wallet';
            }

            AppUsage::create($app_usage);
        }

        $status = Transaction::$statusFailed;

        $txn_id = $request->transaction_id;

        if (
            isset($request->payment_method) && in_array(
                $request->payment_method,
                array(
                    Transaction::$paymentTypeRazorpay,
                    Transaction::$paymentTypePaystack,
                    Transaction::$paymentTypeCashfree
                )
            )
        ) {
            if ($request->payment_method == Transaction::$paymentTypeRazorpay) {
                if (TransactionHelper::verifyRazorpayPayment($txn_id, $gateways)) {
                    $status = Transaction::$statusSuccess;
                }
            } else if ($request->payment_method == Transaction::$paymentTypePaystack) {

                $paystack = new Paystack($gateways);
                $payment = $paystack->verify_transaction($txn_id);

                if (!empty($payment)) {
                    $payment = json_decode($payment, true);
                    if (isset($payment['data']['status']) && $payment['data']['status'] == 'success') {
                        $status = Transaction::$statusSuccess;
                    }
                }
            } else if ($request->payment_method == Transaction::$paymentTypeCashfree) {
                // Cashfree uses webhook-based confirmation. Check if webhook already created
                // a successful transaction for this order. If yes, mark as success.
                // This handles cases where the client calls addTransaction after webhook.
                if ($request->type == 'order') {
                    $existingTxn = Transaction::where('order_id', $order->id)
                        ->where('type', Transaction::$typeCashfree)
                        ->where('status', Transaction::$statusSuccess)
                        ->first();
                    if ($existingTxn) {
                        $status = Transaction::$statusSuccess;
                        Log::info('Cashfree: Transaction already verified by webhook', ['order_id' => $order->id]);
                    }
                } else {
                    // Wallet recharge - check wallet_transactions table
                    $existingWalletTxn = WalletTransaction::where('user_id', auth()->id())
                        ->where('payment_type', Transaction::$paymentTypeCashfree)
                        ->where('status', Transaction::$statusSuccess)
                        ->where('txn_id', $txn_id)
                        ->first();
                    if ($existingWalletTxn) {
                        $status = Transaction::$statusSuccess;
                        Log::info('Cashfree: Wallet transaction already verified by webhook', ['txn_id' => $txn_id]);
                    }
                }
            }
            // Map gateway payment method to normalized key for transactions.type
            $paymentMethod = $request->payment_method;
            $paymentTypeKeyMap = [
                Transaction::$paymentTypeRazorpay => Transaction::$typeRazorpay,
                Transaction::$paymentTypePaystack => Transaction::$typePaystack,
                Transaction::$paymentTypeStripe   => Transaction::$typeStripe,
                Transaction::$paymentTypePaypal   => Transaction::$typePaypal,
                Transaction::$paymentTypeMidtrans => Transaction::$typeMidtrans,
                Transaction::$paymentTypePhonepe  => Transaction::$typePhonepe,
                Transaction::$paymentTypeCashfree => Transaction::$typeCashfree,
                Transaction::$paymentTypePaytabs  => Transaction::$typePaytabs,
            ];
            $paymentTypeKey = $paymentTypeKeyMap[$paymentMethod] ?? $paymentMethod;

            if ($request->type == 'order') {
                $transactionData = array();
                $transactionData['user_id'] = $order->user_id;
                $transactionData['order_id'] = $order->id;
                $transactionData['type'] = $paymentTypeKey;
                $transactionData['txn_id'] = $txn_id;
                $transactionData['payu_txn_id'] = "";
                $transactionData['amount'] = $order->final_total;
                $transactionData['status'] = $status;
                $transactionData['message'] = ($status == Transaction::$statusSuccess) ? 'txn_order_payment' : 'txn_payment_failed';
                $transactionData['transaction_date'] = date('Y-m-d H:i:s');

                $transaction = Transaction::create($transactionData);
                if ($status == Transaction::$statusSuccess) {

                    $order->active_status = OrderStatusList::$received;
                    $order->transaction_id = $transaction->id ?? 0;
                    $order->save();

                    $excludedStatuses = [OrderStatusList::$cancelled, OrderStatusList::$returned];
                    // Update the order items
                    OrderItem::where("order_id", $order->id)
                    ->whereNotIn("active_status", $excludedStatuses)
                    ->update(['active_status' => $order->active_status]);

                    // Online payment confirmed -> commit reservation + consume the cart.
                    CommonHelper::finalizeOnlineOrderSuccess($order);

                    /* deduct wallet balance now that online payment is confirmed (order's country wallet) */
                    if ($order->wallet_balance > 0) {
                        $current_balance = CommonHelper::getUserWalletBalance($order->user_id, $order->country_id);
                        $new_balance = $current_balance < $order->wallet_balance ? 0 : $current_balance - $order->wallet_balance;
                        CommonHelper::updateUserWalletBalance($new_balance, $order->user_id, $order->country_id);
                        CommonHelper::addWalletTransaction($order->id, 0, $order->user_id, 'debit', $order->wallet_balance, 'wallet_used_against_order_placement', 1, $order->payment_method ?? '');
                    }

                    // Online payment confirmed -> all notifications (push + email + SMS)
                    // run AFTER the response so the app isn't blocked on slow gateways.
                    $orderStatusForSms = $order->active_status;
                    try {
                        dispatch(function () use ($order, $orderStatusForSms) {
                            CommonHelper::sendNotificationOrderStatus($order);
                            CommonHelper::sendOrderNotificationsToAdmins($order, 'new_order', $order->delivery_boy_id ?? null);
                            try {
                                CommonHelper::sendSmsOrderStatus($order, $orderStatusForSms);
                            } catch (\Exception $e) {
                                Log::error("addTransaction SMS error :", [$e->getMessage()]);
                            }
                        })->afterResponse();
                    } catch (\Exception $e) {
                        Log::error("addTransaction notification error :", [$e->getMessage()]);
                    }
                    try {
                        dispatch(new SendEmailJob($order))->afterResponse();
                    } catch (\Exception $e) {
                        Log::error("addTransaction send mail error :", [$e->getMessage()]);
                    }

                    return CommonHelper::responseSuccess("Order Placed Successfully");
                } else {
                    $order->active_status = OrderStatusList::$paymentPending;
                    $order->transaction_id = $transaction->id ?? 0;
                    $order->save();
                    try {
                        dispatch(function () use ($order) {
                            CommonHelper::sendPaymentFailedNotification($order);
                        })->afterResponse();
                    } catch (\Exception $e) {
                        Log::error("addTransaction payment failed notification error :", [$e->getMessage()]);
                    }
                    return CommonHelper::responseError("Transaction Failed, Please try again!");
                }
            } elseif ($request->type == 'wallet') {

                $rechargeMeta = CommonHelper::rechargeWalletMeta($rechargeCountryId, $user->id);
                $walletTransactionData = array();
                $walletTransactionData['user_id'] =  $user->id;
                $walletTransactionData['order_id'] = '';
                $walletTransactionData['type'] = 'credit';
                $walletTransactionData['payment_type'] = $request->payment_method;
                $walletTransactionData['txn_id'] = $txn_id;
                $walletTransactionData['amount'] = $request->wallet_amount;
                $walletTransactionData['status'] = $status;
                $walletTransactionData['message'] = ($status == WalletTransaction::$statusSuccess) ? 'wallet_successfully_recharged' : 'wallet_recharge_failed';
                $walletTransactionData['transaction_date'] = date('Y-m-d H:i:s');
                $walletTransactionData['country_id'] = $rechargeMeta['country_id'];
                $walletTransactionData['currency'] = $rechargeMeta['currency'];
                $walletTransactionData['currency_code'] = $rechargeMeta['currency_code'];
                $wallet_transaction = WalletTransaction::create($walletTransactionData);
                if ($status == WalletTransaction::$statusSuccess) {

                    //Mark credit amount in the user's (country-wise) wallet.
                    $newBalance = CommonHelper::addUserWalletBalance($request->wallet_amount, $user->id, $rechargeMeta['country_id']);
                    // Notify after the response (mail/sms/push are slow external calls).
                    $rechargeUserId = (int) $user->id;
                    $rechargeAmount = (float) $request->wallet_amount;
                    $rechargeCid    = $rechargeMeta['country_id'];
                    $rechargeTxn    = $wallet_transaction->txn_id ?? null;
                    try {
                        dispatch(function () use ($rechargeUserId, $rechargeAmount, $rechargeCid, $rechargeTxn) {
                            CommonHelper::notifyWalletRecharge($rechargeUserId, $rechargeAmount, $rechargeCid, $rechargeTxn);
                        })->afterResponse();
                    } catch (\Exception $e) {
                        Log::error("addTransaction wallet recharge notification error :", [$e->getMessage()]);
                    }
                    $data = array();
                    $data['user_balance'] = $newBalance;
                    return CommonHelper::responseSuccessWithData("Amount Added in Wallet Successfully", $data);
                } else {
                    // Capture only scalars in the closure (never $request/models) to keep it serializable.
                    $failUserId    = (int) $user->id;
                    $failAmount    = (float) $request->wallet_amount;
                    $failCountryId = $rechargeMeta['country_id'];
                    try {
                        dispatch(function () use ($failUserId, $failAmount, $failCountryId) {
                            CommonHelper::sendWalletRechargeFailedNotification($failUserId, $failAmount, $failCountryId);
                        })->afterResponse();
                    } catch (\Exception $e) {
                        Log::error("addTransaction wallet recharge failed notification error :", [$e->getMessage()]);
                    }
                    return CommonHelper::responseError("Transaction Failed, Please try again!");
                }
            }
        }
    }

    public function updateOrderStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required',
        ]);

        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }
        // Quick orders cancel the whole order, so the app sends order_id (no item).
        // Ecommerce sends order_item_id. Accept either.
        if (empty($request->order_item_id) && empty($request->order_id)) {
            return CommonHelper::responseError('order_item_id_or_order_id_is_required');
        }

        if (!empty($request->order_item_id)) {
            $order_item = OrderItem::select("*")->where("id", $request->order_item_id)->first();
            if (empty($order_item)) {
                return CommonHelper::responseError('Order Item Not found.');
            }
            $id = $request->order_id ?? $order_item->order_id;
            $order = Order::select("*")->where("id", $id)->first();
        } else {
            // order_id only (quick whole-order operation): use a representative item.
            $order = Order::select("*")->where("id", $request->order_id)->first();
            if (empty($order)) {
                return CommonHelper::responseError('Order Not found.');
            }
            $order_item = OrderItem::where('order_id', $order->id)
                ->where('active_status', '!=', OrderStatusList::$cancelled)
                ->first()
                ?? OrderItem::where('order_id', $order->id)->first();
        }

        if (empty($order)) {
            return CommonHelper::responseError('Order Not found.');
        }
        if (empty($order_item)) {
            return CommonHelper::responseError('Order Item Not found.');
        }
        $order_item_id = $order_item->id;

        $user = User::select("*")->where('id', $order->user_id)->first();
        if (empty($user)) {
            return CommonHelper::responseError('User Not found.');
        }

        $postStatus = $request->status;
        $status = OrderStatusList::where('id', $postStatus)->first();
        if (empty($status)) {
            return CommonHelper::responseError('Status Not found.');
        }
        $selectedStatus = $status->status;
        if ($order_item->active_status == $postStatus) {
            return CommonHelper::responseError("This Order Item is already " . $selectedStatus . "!");
        }

        /* Cannot return order unless it is delivered */
        if (CommonHelper::isOrderItemReturned($order_item->active_status, $postStatus)) {
            return CommonHelper::responseError(__('cannot_return_order_unless_it_is_delivered'));
        }

        /* Could not update order status once cancelled or returned! */
        if (CommonHelper::isOrderItemCancelled($order_item_id)) {
            return CommonHelper::responseError(__('could_not_update_order_status_cancelled_or_returned'));
        }

        /* No status change while payment is still pending (item- or order-level). */
        if ((int) $order_item->active_status === OrderStatusList::$paymentPending
            || (int) $order->active_status === OrderStatusList::$paymentPending) {
            return CommonHelper::responseError(__('cannot_update_status_while_payment_pending'));
        }

        if (!empty($postStatus)) {

            if ($postStatus == OrderStatusList::$delivered) {

                if ($order->payment_method == Transaction::$paymentTypeCod) {

                    // Save Device details
                    if ($request->device_type) {
                        $app_usage = array();
                        $app_usage['order_id'] = $order->id;
                        $app_usage['device_type'] = $request->device_type;
                        $app_usage['app_version'] = $request->app_version;
                        AppUsage::create($app_usage);
                    }

                    $transactionData = array();
                    $transactionData['user_id'] = $order->user_id;
                    $transactionData['order_id'] = $order->id;
                    $transactionData['type'] = "COD";
                    $transactionData['txn_id'] = round(microtime(true) * 1000);
                    $transactionData['payu_txn_id'] = "";
                    $transactionData['amount'] = $order->total;
                    $transactionData['status'] = Transaction::$statusSuccess;
                    $transactionData['message'] = "";
                    $transactionData['transaction_date'] = date('Y-m-d H:i:s');
                    $transaction = Transaction::create($transactionData);
                    $order->transaction_id = $transaction->id ?? 0;
                }

                $order->active_status = OrderStatusList::$delivered;
                $order->save();

                // Credit wallet-type promo cashback now that the order is delivered.
                CommonHelper::creditOrderCashback($order);

                if ($order->channel === 'quick') {
                    // Quick = one status for the whole single-store order.
                    OrderItem::where('order_id', $order->id)
                        ->whereNotIn('active_status', [OrderStatusList::$cancelled, OrderStatusList::$returned])
                        ->update(['active_status' => OrderStatusList::$delivered]);
                } else {
                    $order_item->active_status = OrderStatusList::$delivered;
                    $order_item->save();
                }

                CommonHelper::sendOrderItemStatusMailNotification($order_item, 'order_item_status_update');
                return CommonHelper::responseSuccessWithData('order_status_updated_successfully', $this->orderResponsePayload($order));
                /*Send Notification*/
            } else if ($postStatus == OrderStatusList::$cancelled) {
                DB::beginTransaction();

                if ($order->channel === 'quick') {
                    // Quick = single-store, one status. Cancelling any item cancels the
                    // whole order: pre-cancel + restock the siblings so the current item
                    // becomes the "last item" and the full-order refund path below runs once.
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

                // Count total items and how many are still not cancelled (before we save this one).
                $itemNum = OrderItem::where("order_id", $order->id)->count();
                $lastItemNum = OrderItem::where("order_id", $order->id)
                    ->where('active_status', '!=', OrderStatusList::$cancelled)
                    ->count();

                if ($itemNum == 1 || $lastItemNum == 1) {
                    $order->active_status = OrderStatusList::$cancelled;
                    $order->save();
                }
                $user = User::find($order->user_id);
                // COD: cash isn't collected before delivery, so the only money paid is
                // the wallet balance applied at checkout. Prepaid: the full amount is paid.
                $isCod = $order->payment_method === Transaction::$paymentTypeCod;

                if ($order->channel === 'quick') {
                    // Quick = whole-order cancel. Charges live at the ORDER level (not split
                    // per item), so refund = all item sub_totals (less promo) + delivery +
                    // refundable additional + refundable surge charges. COD: only the wallet
                    // actually applied at checkout was paid.
                    $items = OrderItem::where('order_id', $order->id)->get();
                    $subTotal = floatval($items->sum('sub_total'));

                    if ($isCod) {
                        $refundable = floatval($order->wallet_balance);
                    } else {
                        $addl = CommonHelper::refundableCharges($order->additional_charges);
                        $addlTotal = array_sum(array_column($addl, 'amount'));
                        $surge = CommonHelper::refundableCharges($order->surge_charges);
                        $surgeTotal = array_sum(array_column($surge, 'charge'));
                        $refundable = max(0, round(
                            $subTotal
                            - floatval($order->promo_discount)
                            + floatval($order->delivery_charge)
                            + $addlTotal
                            + $surgeTotal,
                            2
                        ));
                    }
                    // Split the actual refunded amount across items by sub_total so each
                    // item's refund_amount sums to the order-level refund (order.refund_amount).
                    $weights = $items->values()->map(fn ($it) => (float) $it->sub_total)->all();
                    $shares = CommonHelper::splitByWeight($weights, $refundable);
                    foreach ($items->values() as $idx => $it) {
                        $it->refund_amount = $shares[$idx] ?? 0;
                        $it->save();
                    }
                    if ($refundable > 0) {
                        CommonHelper::addUserWalletBalance($refundable, $order->user_id, $order->country_id);
                        CommonHelper::addWalletTransaction($order->id, $order_item->id, $order->user_id, 'credit', $refundable, 'wallet_order_item_cancelled');
                        CommonHelper::notifyWalletRefundCancelled($order, $order_item, $refundable);
                    }
                    $order->refund_amount = $refundable;
                    $order->remaining_total = 0;
                    $order->remaining_final = 0;
                    $order->wallet_balance = 0;
                    $order->save();
                } else {
                    // Ecommerce = item-wise cancel. Refund this item's value + delivery +
                    // refundable charges (prepaid), or just its wallet portion (COD).
                    if ($isCod) {
                        $refundable = floatval($order_item->wallet_balance);
                    } else {
                        $refundable = CommonHelper::computeOrderItemRefund($order_item, true);
                    }
                    $order_item->refund_amount = $refundable;
                    if ($refundable > 0) {
                        CommonHelper::addUserWalletBalance($refundable, $order->user_id, $order->country_id);
                        CommonHelper::addWalletTransaction($order->id, $order_item->id, $order->user_id, 'credit', $refundable, 'wallet_order_item_cancelled');
                        CommonHelper::notifyWalletRefundCancelled($order, $order_item, $refundable);
                    }
                    // Reduce the order's outstanding totals by this item.
                    $order->refund_amount = floatval($order->refund_amount) + $refundable;
                    $order->remaining_total = max(0, floatval($order->remaining_total) - floatval($order_item->sub_total));
                    $order->remaining_final = max(0, floatval($order->remaining_final) - $refundable);
                    if (floatval($order->wallet_balance) > 0) {
                        $order->wallet_balance = max(0, floatval($order->wallet_balance) - floatval($order_item->wallet_balance));
                    }
                    $order->save();
                }
                $order_item->active_status = $postStatus;
                $order_item->cancellation_reason = $request->cancellation_reason;
                $order_item->canceled_at = now();
                $order_item->save();

                // Quick = whole-order cancel → order-level timeline row (order_item_id 0,
                CommonHelper::setOrderStatus([
                    'order_id'      => $order->id,
                    'order_item_id' => $order->channel === 'quick' ? 0 : $order_item->id,
                    'status'        => $postStatus,
                    'created_by'    => auth()->user()->id,
                    'user_type'     => OrderStatus::$userTypeUser,
                ]);

                // Restock the cancelled item per-store (PVSS); product_variants.stock removed.
                ProductHelper::restockStock($order_item->product_variant_id, $order_item->store_id, (int) $order_item->quantity);
                // Ecommerce: order status follows its remaining items.
                CommonHelper::syncEcommerceOrderStatus($order);
                if (isset($order->promo_code) && $order->promo_code != null && isset($order->promo_discount) && $order->promo_discount != null) {
                    $promo_code = explode("(", $order->promo_code);
                    $minimum_order_amount = PromoCode::where('promo_code', $promo_code[0])->first()->minimum_order_amount;
                    if (isset($minimum_order_amount) && $minimum_order_amount != null && $order->total < $minimum_order_amount) {
                        $order_id = $order->id;
                        CommonHelper::updateOrderPromoCode($order_id, $order->promo_discount);
                    }
                }

                DB::commit();
                CommonHelper::sendOrderItemStatusMailNotification($order_item, 'order_item_status_update');
                //Order Item cancelled Send SMS
                try {
                    CommonHelper::sendSmsOrderStatus($order_item, OrderStatusList::$cancelled); // case 7
                } catch (\Exception $e) {
                    Log::error("Place order SMS error :", [$e->getMessage()]);
                }
                try {
                    dispatch(function () use ($order) {
                        CommonHelper::sendOrderNotificationsToAdmins($order, 'order_status_update', $order->delivery_boy_id ?? null);
                    })->afterResponse();
                } catch (\Exception $e) {
                    Log::error("updateOrderStatus admin notification error: " . $e->getMessage());
                }
                return CommonHelper::responseSuccessWithData('order_cancelled_successfully', $this->orderResponsePayload($order));
            } elseif ($postStatus == OrderStatusList::$returned) {
                $validator = Validator::make($request->all(), [
                    'order_item_id' => [
                        'required',
                        Rule::unique('return_requests')->ignore($request->order_item_id),
                    ],
                    // Pickup address for the return (must belong to the customer).
                    'address_id' => [
                        'required',
                        Rule::exists('user_addresses', 'id')->where('user_id', $order_item->user_id),
                    ],
                ], [
                    'order_item_id.unique' => 'Return request has been sent already.',
                ]);
                if ($validator->fails()) {
                    return CommonHelper::responseError($validator->errors()->first());
                }
                // Quick orders are cancel-only — no returns.
                if ($order->channel === 'quick') {
                    return CommonHelper::responseError('returns_are_not_available_for_quick_orders');
                }

                $returnRequest = new ReturnRequest();
                $returnRequest->user_id = $order_item->user_id;
                $returnRequest->product_variant_id = $order_item->product_variant_id;
                $returnRequest->order_id = $request->order_id;
                $returnRequest->order_item_id = $request->order_item_id;
                $returnRequest->address_id = $request->address_id;
                // Snapshot the pickup address object (same shape as orders.address).
                $returnRequest->address = CommonHelper::userAddressData(CommonHelper::getUserAddress($request->address_id));
                $returnRequest->return_reason = $request->return_reason;
                $returnRequest->status = 1;    //request is pending
                $returnRequest->delivery_boy_id = 0;    //request is pending, so no delivery boy assigned
                $returnRequest->remarks = $request->remarks ?? '';
                $returnRequest->save();

                $returnRequest->return_number = CommonHelper::formatNumber(Setting::get_value('return_request_prefix') ?: 'RET-', $returnRequest->id);
                $returnRequest->saveQuietly();

                // Seed the status history with the initial pending state (for the timeline).
                CommonHelper::setReturnRequestStatus($returnRequest->id, $returnRequest->status, $order_item->user_id, null);

                // Push notifications to customer (return_request_customer)
                CommonHelper::sendReturnRequestNotification($returnRequest);
                // Panel notifications for seller and admin: "You have a request for product return".
                CommonHelper::sendReturnRequestPanelNotifications($returnRequest);

                CommonHelper::sendOrderItemStatusMailNotification($order_item, 'return_request_sent');
            
                return CommonHelper::responseSuccessWithData('order_return_request_sent_successfully', $this->orderResponsePayload($order));
            } else {

                if ($order->channel === 'quick') {
                    // Quick = one status for the whole single-store order.
                    $order->active_status = $postStatus;
                    $order->save();
                    OrderItem::where('order_id', $order->id)
                        ->whereNotIn('active_status', [OrderStatusList::$cancelled, OrderStatusList::$returned])
                        ->update(['active_status' => $postStatus]);
                } else {
                    $order_item->active_status = $postStatus;
                    $order_item->save();
                }
                CommonHelper::sendOrderItemStatusMailNotification($order_item, 'order_item_status_update');

                try {
                    dispatch(function () use ($order) {
                        CommonHelper::sendOrderNotificationsToAdmins($order, 'order_status_update', $order->delivery_boy_id ?? null);
                    })->afterResponse();
                } catch (\Exception $e) {
                    Log::error("updateOrderStatus admin notification error: " . $e->getMessage());
                }

                return CommonHelper::responseSuccessWithData('order_status_updated_successfully', $this->orderResponsePayload($order));
            }
        }
    }

    /**
     * Post cancel/return payload in the SAME shape the order lists use, scoped to this
     * one order: quick -> getOrders shape, ecommerce -> getEcomOrders (item-wise).
     */
    private function orderResponsePayload($order)
    {
        $req = new Request(['order_id' => $order->id, 'limit' => 200, 'offset' => 0]);
        $resp = ($order->channel === 'quick') ? $this->getOrders($req) : $this->getEcomOrders($req);
        $payload = (is_object($resp) && method_exists($resp, 'getData')) ? $resp->getData(true) : [];
        return $payload['data'] ?? [];
    }

    public function getOrders(Request $request)
    {
        $limit = ($request->limit) ?? 12;
        $offset = ($request->offset) ?? 0;

        $order_id = $request->order_id;
        $user_id = auth()->user()->id;
        // Quick orders only — ecommerce orders are listed item-wise via getEcomOrders.
        $channel = 'quick';

        // Optional date range (inclusive), on the order date.
        $startDate = (!empty($request->start_date)) ? $request->start_date . ' 00:00:00' : null;
        $endDate   = (!empty($request->end_date)) ? $request->end_date . ' 23:59:59' : null;

        $sql = Order::select(DB::raw("count(id) as total"))
            ->where("user_id", $user_id)
            ->where("channel", $channel);
        if (!empty($order_id)) {
            $sql = $sql->where("id", $order_id);
        }
        if ($startDate && $endDate) {
            $sql = $sql->whereBetween('created_at', [$startDate, $endDate]);
        }

        if (isset($request->order_status_id) && $request->order_status_id != 0 && $request->order_status_id != "") {
            $sql = $sql->where("active_status", "=", $request->order_status_id);
        }

        if (isset($request->type)) {
            $activeTypeStatus = [OrderStatusList::$paymentPending, OrderStatusList::$received, OrderStatusList::$processed, OrderStatusList::$outForDelivery, OrderStatusList::$shipped, OrderStatusList::$preparing, OrderStatusList::$readyForPickup, OrderStatusList::$pickedUp];
            $previousTypeStatus = [OrderStatusList::$delivered, OrderStatusList::$cancelled, OrderStatusList::$returned];
            if ($request->type == Order::$activeType) {
                $sql = $sql->whereIn('orders.active_status', $activeTypeStatus);
            } else {
                $sql = $sql->whereIn('orders.active_status', $previousTypeStatus);
            }
        }

        $total = $sql->first();
        $sql = Order::select(
            "orders.*",
            'orders.id as order_id',
            "dboys.name as delivery_boy_name",
            "dboys.mobile as delivery_boy_mobile",
            DB::raw('(select name from users as u where u.id = orders.user_id) as user_name')
        )->from("orders")
            ->leftJoin('delivery_boys as dboys', 'orders.delivery_boy_id', '=', 'dboys.id')
            ->where("orders.user_id", "=", $user_id)
            ->where("orders.channel", $channel);
        if (!empty($order_id)) {
            $sql = $sql->where("orders.id", "=", $order_id);
        }
        if ($startDate && $endDate) {
            $sql = $sql->whereBetween('orders.created_at', [$startDate, $endDate]);
        }

        if (isset($request->order_status_id) && $request->order_status_id != 0 && $request->order_status_id != "") {
            $sql = $sql->where("orders.active_status", "=", $request->order_status_id);
        }

        if (isset($request->type)) {
            $activeTypeStatus = [OrderStatusList::$paymentPending, OrderStatusList::$received, OrderStatusList::$processed, OrderStatusList::$outForDelivery, OrderStatusList::$shipped, OrderStatusList::$preparing, OrderStatusList::$readyForPickup, OrderStatusList::$pickedUp];
            $previousTypeStatus = [OrderStatusList::$delivered, OrderStatusList::$cancelled, OrderStatusList::$returned];
            if ($request->type == Order::$activeType) {
                $sql = $sql->whereIn('orders.active_status', $activeTypeStatus);
            } else {
                $sql = $sql->whereIn('orders.active_status', $previousTypeStatus);
            }
        }
        $res = $sql->orderBy("orders.id", "DESC")->skip($offset)->take($limit)->get();

        $productRatingSetting = (int) (Setting::get_value('product_rating') ?? 0);
        $isProductRatingEnabled = $productRatingSetting === 1;
        $res = $res->makeHidden(['image', 'updated_at', 'deleted_at', 'current_status', 'address_id', 'order_id', 'orders_id', 'status']);

        $langCode = app('lang_code') ?? 'en';
        app()->setLocale($langCode);

        $i = 0;
        foreach ($res as $key => $row) {
            $res[$key]->order_status_name = OrderStatusList::getCustomerTranslatedName((int) $row->active_status);
            $res[$key]->saved_amount = (float) ($row->saved_amount ?? 0);
            $res[$key]->refund_amount = (float) ($row->refund_amount ?? 0);
            // Order-wise status timeline (replaces the legacy `status` JSON snapshot).
            $res[$key]->timeline = OrderStatus::where('order_id', $row->id)
                ->where('order_item_id', 0)->orderBy('id', 'ASC')->get()
                ->map(fn ($s) => [
                    'status' => (int) $s->status,
                    'status_name' => OrderStatusList::getCustomerTranslatedName((int) $s->status),
                    'datetime' => $s->created_at,
                ])->values();
            // `address` is the snapshot object (orders.address array cast).
            if (is_string($row->additional_charges)) {
                $res[$i]['additional_charges'] = json_decode($row->additional_charges, true) ?? [];
            } elseif (is_array($row->additional_charges)) {
                $res[$i]['additional_charges'] = $row->additional_charges;
            } else {
                $res[$i]['additional_charges'] = [];
            }
            if (is_string($row->surge_charges)) {
                $res[$i]['surge_charges'] = json_decode($row->surge_charges, true) ?? [];
            } elseif (is_array($row->surge_charges)) {
                $res[$i]['surge_charges'] = $row->surge_charges;
            } else {
                $res[$i]['surge_charges'] = [];
            }

            $generate_otp = Setting::get_value("generate_otp");
            if ($generate_otp == 0) {
                $res[$key]->otp = 0;
            }
            $product_rating = Setting::get_value("product_rating");
            $res[$key]->product_rating = $product_rating ?? 0;

            $row->promo_code = explode('(', $row->promo_code)[0];

            $res[$i]['date'] = $row->created_at;
            $res[$i]['created_at'] = $row->created_at;

            $orderStatus = orderStatus::where('order_id', $row['id'])->get();
            $data = array();
            foreach ($orderStatus as $status) {
                $subData = array();
                array_push($subData, $status->status, $status->created_at);
                array_push($data, $subData);
            }
            $res[$i]['status'] = json_encode($data);

            $items = OrderItem::with('images')->select(
                'oi.*',
                'v.id as variant_id',
                'p.id as product_id',
                'v.name',
                'p.image',
                'p.manufacturer',
                'p.made_in',
                's.name as store_name',
                's.formatted_address as store_address',
                's.latitude as store_latitude',
                's.longitude as store_longitude',
                DB::raw('(SELECT status FROM return_requests WHERE order_item_id = oi.id) as return_requested'),
                DB::raw('(SELECT return_reason FROM return_requests WHERE order_item_id = oi.id) as return_reason'),
                DB::raw('(SELECT remarks FROM return_requests WHERE order_item_id = oi.id) as return_remarks')
            )
                ->from('order_items as oi')
                ->leftJoin('product_variants as v', 'oi.product_variant_id', '=', 'v.id')
                ->leftJoin('products as p', 'v.product_id', '=', 'p.id')
                ->leftJoin('stores as s', 'oi.store_id', '=', 's.id')
                ->where('oi.order_id', '=', $row['id'])
                ->orderBy('p.id', 'ASC')
                ->orderBy('oi.id', 'ASC')
                ->get();

            $cancelableStatusList = array(OrderStatusList::$received, OrderStatusList::$processed, OrderStatusList::$shipped, OrderStatusList::$outForDelivery, OrderStatusList::$preparing, OrderStatusList::$readyForPickup, OrderStatusList::$pickedUp, );

            // Quick = order-wise cancel: the whole order is cancellable only when EVERY
            // item is cancellable (one non-cancelable product blocks the order) and the
            // ORDER status is still within each item's till_status (in-progress only).
            $orderCancellable = count($items) > 0;

            foreach ($items as $subkey => $item) {

                $items[$subkey]->made_in = $item->made_in ?? "";
                $items[$subkey]->created_at = $item->created_at;

                $basePrice = (float) $item->price;
                // discounted_price: DB value when > 0, else fall back to the base price.
                $baseDisc  = (float) ($item->discounted_price > 0 ? $item->discounted_price : $item->price);
                
                // NEW GST SYSTEM: Check if item has GST fields set (gst_rate, gst_inclusive)
                // If yes, respect the new GST system and don't apply old tax logic
                if ($item->gst_rate !== null && $item->gst_rate !== '' && $item->gst_inclusive !== null) {
                    // Item has new GST fields - use them as-is, don't add old tax
                    $items[$subkey]->price = (float) $basePrice;
                    $items[$subkey]->discounted_price = (float) $baseDisc;
                } else {
                    // LEGACY TAX SYSTEM: Item doesn't have GST fields, use old tax percentage
                    $taxed = ProductHelper::getTaxableAmount($item->product_variant_id);
                    $percentage = (float) ($taxed->percentage ?? 0);
                    $items[$subkey]->price = (float) CommonHelper::doubleNumber($basePrice + $basePrice * ($percentage / 100));
                    $items[$subkey]->discounted_price = (float) CommonHelper::doubleNumber($baseDisc + $baseDisc * ($percentage / 100));
                }

                $itemCancellable = (($item->cancelable_status == 1)
                    && intval($row->active_status) <= intval($item->till_status)
                    && in_array($row->active_status, $cancelableStatusList));
                $orderCancellable = $orderCancellable && $itemCancellable;

                $items[$subkey]->item_rating = CommonHelper::productRatingOfUser($item->product_id, $item->user_id);
            }
            $items = $items->makeHidden(['image', 'images', 'updated_at', 'deleted_at', 'status', 'current_status', 'cancelable_status', 'till_status', 'return_status', 'return_days', 'courier_agency', 'tracking_id', 'tracking_url']);

            $res[$i]['items'] = $items;
            // Order-level flags for quick orders (cancel order-wise, never returnable).
            $res[$i]['is_cancellable'] = $orderCancellable;
            $res[$i]['is_returnable'] = false;
            $res[$i]['product_rating'] = $isProductRatingEnabled;
            // Quick orders aren't returnable → forward-delivery chat visibility only.
            $res[$i]['is_delivery_boy_chat_visible'] = CommonHelper::isDeliveryBoyChatVisibleForward(
                $row->delivery_boy_id ? (int) $row->delivery_boy_id : null,
                (int) $row->active_status
            );

            $i++;
        }

        if (!empty($res) && $total->total !== 0) {
            return CommonHelper::responseWithData($res, $total->total);
        } else {
            return CommonHelper::responseError(__('no_orders_found'));
        }
    }

    /**
     * Ecommerce orders are managed item-wise, so the customer list is paginated by
     * ORDER ITEM. Each entry = one item (its own status, charges, delivery boy,
     * payable) + its order context + sibling items of the same order.
     */
    public function getEcomOrders(Request $request)
    {
        $limit  = (int) ($request->limit ?? 12);
        $offset = (int) ($request->offset ?? 0);
        $user_id = auth()->user()->id;

        $base = OrderItem::from('order_items as oi')
            ->join('orders as o', 'oi.order_id', '=', 'o.id')
            ->where('o.user_id', $user_id)
            ->where('o.channel', 'ecommerce');

        if (!empty($request->order_id)) {
            $base->where('oi.order_id', $request->order_id);
        }
        if (!empty($request->order_item_id)) {
            $base->where('oi.id', $request->order_item_id);
        }
        // Optional date range (inclusive), on the order date.
        if (!empty($request->start_date) && !empty($request->end_date)) {
            $base->whereBetween('o.created_at', [$request->start_date . ' 00:00:00', $request->end_date . ' 23:59:59']);
        }
       
        if (isset($request->type)) {
            $active = [OrderStatusList::$paymentPending, OrderStatusList::$received, OrderStatusList::$processed, OrderStatusList::$outForDelivery, OrderStatusList::$shipped, OrderStatusList::$preparing, OrderStatusList::$readyForPickup, OrderStatusList::$pickedUp];
            $previous = [OrderStatusList::$delivered, OrderStatusList::$cancelled, OrderStatusList::$returned];
            $base->whereIn('oi.active_status', $request->type == Order::$activeType ? $active : $previous);
        }

        $total = (clone $base)->count('oi.id');

        $items = (clone $base)->select(
            'oi.*',
            'o.id as order_id', 'o.order_number', 'o.channel', 'o.payment_method', 'o.address as order_address',
            'o.currency', 'o.currency_code', 'o.created_at as order_created_at',
            'o.cashback_amount', 'o.cashback_credited', 'o.saved_amount',
            'v.id as variant_id', 'v.name', 'p.id as product_id', 'p.image',
            's.name as store_name',
            'db.name as delivery_boy_name', 'db.mobile as delivery_boy_mobile',
            DB::raw('(SELECT status FROM return_requests WHERE order_item_id = oi.id) as return_requested'),
            DB::raw('(SELECT reject_reason FROM return_requests WHERE order_item_id = oi.id AND status = 3 LIMIT 1) as return_reject_reason'),
            DB::raw('(SELECT return_reason FROM return_requests WHERE order_item_id = oi.id LIMIT 1) as return_reason')
            )
            ->leftJoin('product_variants as v', 'oi.product_variant_id', '=', 'v.id')
            ->leftJoin('products as p', 'v.product_id', '=', 'p.id')
            ->leftJoin('stores as s', 'oi.store_id', '=', 's.id')
            ->leftJoin('delivery_boys as db', 'oi.delivery_boy_id', '=', 'db.id')
            ->orderBy('oi.id', 'DESC')
            ->skip($offset)->take($limit)->get();

        if ($items->isEmpty()) {
            return CommonHelper::responseWithData([], $total);
        }

        $isProductRatingEnabled = ((int) (Setting::get_value('product_rating') ?? 0)) === 1;

        // Bulk-fetch siblings (other items of the same orders) for the "other items" list.
        $orderIds = $items->pluck('order_id')->unique()->values()->all();
        $siblingsByOrder = OrderItem::from('order_items as oi')
            ->leftJoin('product_variants as v', 'oi.product_variant_id', '=', 'v.id')
            ->leftJoin('products as p', 'v.product_id', '=', 'p.id')
            ->whereIn('oi.order_id', $orderIds)
            ->orderBy('oi.id', 'ASC')
            ->get(['oi.id', 'oi.order_id', 'oi.product_name', 'oi.quantity', 'oi.active_status', 'oi.final_total', 'p.image'])
            ->groupBy('order_id');

        // Return-request status per sibling item (for other_items' order_item_status).
        $siblingIds = $siblingsByOrder->flatten(1)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $returnStatusByItem = ReturnRequest::whereIn('order_item_id', $siblingIds)->pluck('status', 'order_item_id');

        foreach ($items as $item) {
            $basePrice = (float) $item->price;
            $baseDisc  = (float) ($item->discounted_price > 0 ? $item->discounted_price : $item->price);
            
            // NEW GST SYSTEM: Check if item has GST fields set (gst_rate, gst_inclusive)
            // If yes, respect the new GST system and don't apply old tax logic
            if ($item->gst_rate !== null && $item->gst_rate !== '' && $item->gst_inclusive !== null) {
                // Item has new GST fields - use them as-is, don't add old tax
                $item->price = (float) $basePrice;
                $item->discounted_price = (float) $baseDisc;
            } else {
                // LEGACY TAX SYSTEM: Item doesn't have GST fields, use old tax percentage
                $percentage = (float) (ProductHelper::getTaxableAmount($item->product_variant_id)->percentage ?? 0);
                $item->price = (float) CommonHelper::doubleNumber($basePrice + $basePrice * ($percentage / 100));
                $item->discounted_price = (float) CommonHelper::doubleNumber($baseDisc + $baseDisc * ($percentage / 100));
            }
            // Handle image URL properly - detect Cloudinary URLs
            if ($item->image) {
                // If image is already a full URL (Cloudinary), use as-is
                if (preg_match('~^https?://~', $item->image)) {
                    $item->image = $item->image;
                } else {
                    // Otherwise treat as local storage path
                    $item->image = asset('storage/' . $item->image);
                }
            } else {
                $item->image = '';
            }
            $item->otp = (!empty($item->tracking_id)) ? 0 : (int) $item->otp;
            $item->order_status_name = OrderStatusList::getCustomerTranslatedName((int) $item->active_status);
            // Item status name: the return status once a return exists, else the active status.
            $item->order_item_status = ($item->return_requested !== null)
                ? ReturnStatusList::getTranslatedName((int) $item->return_requested)
                : OrderStatusList::getCustomerTranslatedName((int) $item->active_status);
            $item->date = $item->order_created_at;
            // Single address object (snapshot) for the order.
            $item->address = CommonHelper::addressObject($item->order_address);
            unset($item->order_address);
            // Ratings (parity with quick orders): user's rating of this product + feature flag.
            $item->item_rating = CommonHelper::productRatingOfUser($item->product_id, $user_id);
            $item->product_rating = $isProductRatingEnabled;
            // Decode per-item charge JSON.
            foreach (['additional_charges', 'surge_charges'] as $jf) {
                $v = $item->{$jf} ?? null;
                $item->{$jf} = is_string($v) ? (json_decode($v, true) ?: []) : (is_array($v) ? $v : []);
            }
            $item->cashback_amount = (float) $item->cashback_amount;
            $item->saved_amount = (float) ($item->saved_amount ?? 0);

            // Delivery-boy chat visibility (ecommerce, item-wise): forward delivery while live,
            // or the return pickup while a return is in progress.
            $item->is_delivery_boy_chat_visible = CommonHelper::isDeliveryBoyChatVisibleForOrderItem(
                (int) $item->id,
                $item->delivery_boy_id ? (int) $item->delivery_boy_id : null,
                (int) $item->active_status
            );

            // Cancelable / returnable for this item.
            $cancelableStatusList = [OrderStatusList::$received, OrderStatusList::$processed, OrderStatusList::$shipped, OrderStatusList::$outForDelivery, OrderStatusList::$preparing, OrderStatusList::$readyForPickup, OrderStatusList::$pickedUp];
            // Once a return has been applied (a request exists) or the item is already
            // cancelled, it can be neither cancelled nor returned again.
            $returnRequestId = ReturnRequest::where('order_item_id', $item->id)->value('id');
            $blocked = $returnRequestId !== null || (int) $item->active_status === OrderStatusList::$cancelled;

            // Ecommerce: cancel allowed when the product is cancelable and the ITEM's
            // active status is still within the configured till_status (in-progress only).
            $item->is_cancellable = (!$blocked
                && $item->cancelable_status == 1
                && in_array((int) $item->active_status, $cancelableStatusList, true)
                && (int) $item->active_status <= (int) $item->till_status) ? true : false;
            // Return allowed when returnable, within return_days of placement, and delivered.
            $orderDays = abs((int) (date_diff(date_create(date('Y-m-d', strtotime($item->order_created_at))), date_create(date('Y-m-d')))->format('%R%a')));
            $item->is_returnable = (!$blocked
                && $item->return_status == 1
                && $orderDays <= (int) $item->return_days
                && (int) $item->active_status === OrderStatusList::$delivered) ? true : false;
            unset($item->order_created_at); // internal only — not exposed
            // Per-item timeline.
            $item->timeline = OrderStatus::where('order_id', $item->order_id)
                ->where('order_item_id', $item->id)->orderBy('id', 'ASC')->get()
                ->map(fn ($s) => [
                    'status' => (int) $s->status,
                    'status_name' => OrderStatusList::getCustomerTranslatedName((int) $s->status),
                    'datetime' => $s->created_at,
                ])->values();
            // If the item was returned, append the return request's status timeline after
            // the order timeline (so it continues right after the "returned" entry).
            if ($returnRequestId) {
                $returnTimeline = ReturnRequestStatus::where('return_request_id', $returnRequestId)
                    ->orderBy('id', 'ASC')->get()
                    ->map(fn ($s) => [
                        'status' => (int) $s->status,
                        'status_name' => ReturnStatusList::getTranslatedName((int) $s->status),
                        'datetime' => $s->created_at,
                        'is_return' => true,
                    ])->values();
                if ($returnTimeline->isNotEmpty()) {
                    $item->timeline = collect($item->timeline)->concat($returnTimeline)->values();
                }
            }
            // Other items of the same order.
            $item->other_items = collect($siblingsByOrder->get($item->order_id, collect()))
                ->reject(fn ($s) => (int) $s->id === (int) $item->id)
                ->map(fn ($s) => [
                    'order_item_id' => (int) $s->id,
                    'product_name'  => $s->product_name,
                    'quantity'      => $s->quantity,
                    'final_total'   => (float) $s->final_total,
                    'image'         => $s->image 
                        ? (preg_match('~^https?://~', $s->image) ? $s->image : asset('storage/' . $s->image))
                        : '',
                    'status'        => (int) $s->active_status,
                    'status_name'   => OrderStatusList::getCustomerTranslatedName((int) $s->active_status),
                    'order_item_status' => $returnStatusByItem->has((int) $s->id)
                        ? ReturnStatusList::getTranslatedName((int) $returnStatusByItem[(int) $s->id])
                        : OrderStatusList::getCustomerTranslatedName((int) $s->active_status),
                ])->values();
        }

        $items = $items->makeHidden(['created_at', 'updated_at', 'deleted_at', 'status', 'image_url', 'cancelable_status', 'till_status', 'return_status']);
        return CommonHelper::responseWithData($items, $total);
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
    
    public function getLiveTrackingDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|numeric|exists:orders,id',
        ]);

        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        // Retrieve the order ID from the request
        $orderId = $request->input('order_id');

        // Fetch the live tracking details based on the order ID
        $trackingData = LiveTracking::where('order_id', $orderId)
            ->orderBy('id', 'desc')
            ->first();

        // Check if the tracking data exists
        if ($trackingData) {
            return CommonHelper::responseSuccessWithData("Live Tracking Detail fetched successfully.", $trackingData);
        } else {
            return CommonHelper::responseError("Live Tracking Not available.");
        }
    }
}
