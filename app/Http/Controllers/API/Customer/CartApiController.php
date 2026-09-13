<?php

namespace App\Http\Controllers\API\Customer;

use App\Helpers\CommonHelper;
use App\Helpers\CustomerProductShaper;
use App\Helpers\ProductHelper;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\ProductRecommendation;
use App\Models\ProductVariant;
use App\Models\ProductVariantStoreStock;
use App\Models\Setting;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Response;

class CartApiController extends Controller
{
    public function getUserCart(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'latitude' => 'required',
            'longitude' => 'required',
        ], [
            'latitude.required' => 'The latitude field is required.',
            'longitude.required' => 'The longitude field is required.'
        ]);
        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        $user_id = $request->user('api-customers') ? $request->user('api-customers')->id : '';
        return $this->buildUserCartResponse($request, $user_id);
    }

    /**
     * Build the full cart response (same shape as getUserCart). Used by getUserCart,
     * addToCart, and removeFromCart so clients always get the same payload.
     * lat/lng are taken from the request; when absent, delivery charge is skipped.
     */
    private function buildUserCartResponse(Request $request, $user_id)
    {

        $channel = strtolower(trim((string) $request->header('channel')));
        if (!in_array($channel, ['quick', 'ecommerce'], true)) {
            $channel = null;
        }

        if (!ProductHelper::isItemAvailableInUserCart($user_id)) {
            return CommonHelper::responseError('no_items_found_in_users_cart');
        }

        $variant_ids = ($request->variant_ids && $request->variant_ids !== '')
            ? explode(',', $request->variant_ids)
            : [];

        $cartRowsQuery = Cart::select(
            'carts.id',
            'carts.product_id',
            'carts.product_variant_id',
            'carts.store_id',
            'carts.qty',
            'carts.channel'
        )
            ->Join('products', 'carts.product_id', '=', 'products.id')
            ->Join('product_variants', 'carts.product_variant_id', '=', 'product_variants.id')
            ->where('user_id', '=', $user_id);
        if (!empty($variant_ids)) {
            $cartRowsQuery->whereIn('carts.product_variant_id', $variant_ids);
        }
        if ($channel !== null) {
            $cartRowsQuery->where(function ($q) use ($channel) {
                $q->where('carts.channel', $channel)->orWhereNull('carts.channel');
            });
        }
        $cartRows = $cartRowsQuery->orderBy('carts.created_at', 'DESC')->get();

        $lat = $request->filled('latitude') ? (float) $request->latitude : null;
        $lng = $request->filled('longitude') ? (float) $request->longitude : null;

        // Zone resolved once for the whole request.
        $zone = ($lat !== null && $lng !== null)
            ? CommonHelper::getDeliverableCity($lat, $lng, $channel)
            : null;

        // Get company state first
        $companyState = Setting::get_value('company_state') ?? 'Maharashtra';

        // Get STORE state from the store that's fulfilling this order
        // Get CUSTOMER state from the delivery address (sent by frontend)
        $storeState = null;
        $customerState = $request->input('address_state');
        
        // If we have a store in this cart, get its state
        if (!empty($store_ids)) {
            $storeData = Store::whereIn('id', $store_ids)
                ->select('id', 'state')
                ->first();
            if ($storeData) {
                $storeState = $storeData->state;
            }
        }
        
        // If store state not found, try from zone
        if (!$storeState && $zone) {
            $storeState = $zone->state;
        }
        
        // Final fallback for store state
        if (!$storeState) {
            $storeState = $companyState;
        }
        
        // If customer state not provided, try from zone
        if (!$customerState && $zone) {
            $customerState = $zone->state;
        }
        
        // Final fallback for customer state
        if (!$customerState) {
            $customerState = $companyState;
        }
        
        \Log::debug('[GST_CART] Store vs Customer State:', [
            'store_state' => $storeState,
            'customer_state' => $customerState,
            'states_match' => strtoupper($storeState ?? '') === strtoupper($customerState ?? '') ? 'YES (CGST+SGST)' : 'NO (IGST)',
        ]);
        // Pre-resolve the zone's store IDs once (avoids calling resolveCartStore per row,
        // which would re-run getDeliverableCity + PVSS query N times).
        $zoneStoreIds = [];
        if ($zone) {
            $zoneCol = 'zone_id';
            $zoneStoreIds = Store::where('status', 1)
                ->where($zoneCol, $zone->id)
                ->pluck('id')
                ->all();
        }

        // Bulk-resolve best store per variant in 1 query (replaces N resolveCartStore calls).
        $cartVariantIds = $cartRows->pluck('product_variant_id')->all();
        $bulkStoreMap = [];
        if ($lat !== null && $lng !== null && !empty($cartVariantIds)) {
            $pvssQuery = ProductVariantStoreStock::where('is_listed', 1)
                ->whereIn('product_variant_id', $cartVariantIds);
            if (!empty($zoneStoreIds)) {
                $pvssQuery->whereIn('store_id', $zoneStoreIds);
            }
            $pvssRows = $pvssQuery->orderByDesc('stock_status')
                ->orderByDesc('available')
                ->get(['product_variant_id', 'store_id']);
            foreach ($pvssRows as $pvss) {
                $vid = (int) $pvss->product_variant_id;
                if (!isset($bulkStoreMap[$vid])) {
                    $bulkStoreMap[$vid] = (int) $pvss->store_id;
                }
            }
        }

        foreach ($cartRows as $row) {
            $newStore = ($lat !== null && $lng !== null)
                ? ($bulkStoreMap[(int) $row->product_variant_id] ?? null)
                : $row->store_id;
            $oldStore = $row->store_id !== null ? (int) $row->store_id : null;
            if ($newStore !== $oldStore) {
                if ($oldStore) {
                    ProductHelper::releaseStock($row->product_variant_id, $oldStore, (int) $row->qty);
                }
                if ($newStore) {
                    ProductHelper::reserveStock($row->product_variant_id, $newStore, (int) $row->qty);
                }
                Cart::where('id', $row->id)->update(['store_id' => $newStore]);
                $row->store_id = $newStore;
            }
        }

        $store_ids   = array_values(array_unique(array_filter($cartRows->pluck('store_id')->all())));
        $product_ids = array_values(array_unique($cartRows->pluck('product_id')->all()));

        $storeInfo = Store::whereIn('id', $store_ids ?: [0])->get(['id', 'name', 'latitude', 'longitude'])->keyBy('id');

        $products = Product::with([
            'variants',
            'variants.images',
            'variants.storeStocks' => fn($q) => $q->whereIn('store_id', $store_ids ?: [0]),
            'variants.attributeValues.attribute.translations',
            'variants.attributeValues.attributeValue.translations',
            'brand.translations',
            'category.translations',
            'tax',
            'ratings',
            'translations',
        ])->whereIn('id', $product_ids)->get()->keyBy('id');

        $favoriteIds = [];
        if ($user_id && !empty($product_ids)) {
            $favoriteIds = Favorite::where('user_id', $user_id)
                ->whereIn('product_id', $product_ids)
                ->pluck('product_id')->all();
        }

        $res = [];
        foreach ($cartRows as $row) {
            $product = $products[$row->product_id] ?? null;
            if (!$product) {
                continue;
            }

            $store = $row->store_id ? ($storeInfo[$row->store_id] ?? null) : null;
            $storeName = $store ? $store->name : null;

            $res[] = CustomerProductShaper::shapeCartItem(
                $product,
                (int) $row->product_variant_id,
                (int) $row->qty,
                $favoriteIds,
                0,
                $storeName,
                CommonHelper::countryCurrency($zone?->country),
                $row->store_id ? (int) $row->store_id : null,
                $storeState,  // ← Store state, not company state!
                $customerState  // ← Customer delivery address state
            );
        }

        if (empty($res)) {
            return CommonHelper::responseError('no_items_found_in_users_cart');
        }

        $total = CommonHelper::getCartCount($user_id, $channel);
        $sub_total = $total->sub_total; // Use base price without tax
        $saved_amount = $total->save_price - $total->total_amount;
        $saved_amount = ($saved_amount <= 0) ? 0 : $saved_amount;

        $response = [];

        // ---- COD availability (country-wise, from countries.payment_gateways) ----
        $response['cod_allowed'] = CommonHelper::isCodAllowed($zone?->country, $res);

        $response['product_variant_id'] = $total->product_variant_id;
        $response['quantity'] = $total->quantity;

        // ---- Store -> customer display distance (straight-line, no HTTP) ----
        $distance = null;
        $timeToDeliver = 0;
        $distance_unit = $zone ? CommonHelper::normalizeDistanceUnit($zone->distance_unit ?? 'km') : 'km';
        $perUnit = $zone ? (float) ($zone->travel_time_per_km ?? 0) : 0;
        if ($lat !== null && $lng !== null) {
            foreach ($storeInfo as $store) {
                if ($store->latitude === null || $store->longitude === null) {
                    continue;
                }
                $km = CommonHelper::straightLineDistanceKm($lat, $lng, $store->latitude, $store->longitude);
                $dist = CommonHelper::convertKmToUnit($km, $distance_unit);
                if ($dist !== null && ($distance === null || $dist < $distance)) {
                    $distance = $dist;
                    if ($channel === 'quick' && $perUnit > 0) {
                        $timeToDeliver = (int) ceil($dist * $perUnit);
                    }
                }
            }
        }
        $response['distance'] = $distance !== null ? round($distance, 2) . ' ' . $distance_unit : null;
        $response['time_to_deliver'] = CustomerProductShaper::formatDeliveryTime($timeToDeliver, false);

        $minimum_order_amount = 0;
        if ($channel === 'quick' && $zone) {
            $minimum_order_amount = (float) $zone->minimum_order_amount;
        }
        $response['minimum_order_amount'] = $minimum_order_amount;

        $currency = CommonHelper::countryCurrency($zone?->country);
        $response['currency'] = $currency['currency'];
        $response['currency_code'] = $currency['currency_code'];
        $response['decimal_point'] = $currency['decimal_point'];

        // ---- Promocode (only when supplied) ----
        $promo_discount = 0;
        if (isset($request->promocode_id) && $request->promocode_id && $request->promocode_id != "") {
            $promocode_details = CommonHelper::getValidatedPromoCode($request->promocode_id, $sub_total, $user_id, [
                'latitude'  => $lat ?? null,
                'longitude' => $lng ?? null,
                'channel'   => $channel,
                'platform'  => strtolower(trim((string) $request->input('platform'))) ?: null,
            ]);
            $response['promocode_details'] = $promocode_details;
            $promo_discount = (($promocode_details['discount_apply_type'] ?? '') === 'wallet')
                ? 0 : ($promocode_details['discount'] ?? 0);
        }

        // ---- Promo nudge ----
        $nudge = CommonHelper::getCartPromoNudge($user_id, $sub_total, (int) ($total->cart_total_qty ?? 0), [
            'latitude'  => $lat ?? null,
            'longitude' => $lng ?? null,
            'channel'   => $channel,
            'platform'  => strtolower(trim((string) $request->input('platform'))) ?: null,
        ]);
        $response['unlock_message'] = $nudge['unlock_message'];
        $response['unlock_promo_code'] = $nudge['unlock_promo_code'];
        $response['unlock_promo_code_id'] = $nudge['unlock_promo_code_id'];

        // ---- Delivery charge ----
        // The address must sit in a deliverable zone at all. `$zone` is that check;
        // without it the address is undeliverable regardless of what the per-store
        // distance calculation would return.
        $data = ($lat !== null && $lng !== null && $zone)
            ? CommonHelper::getAllDeliveryCharge($lat, $lng, $store_ids, $sub_total, $channel)
            : ['status' => 0];

        // Calculate total GST from all cart items
        $total_gst = 0;
        foreach ($res as $item) {
            if (isset($item['gst']) && is_array($item['gst'])) {
                $total_gst += (float) ($item['gst']['cgst'] ?? 0);
                $total_gst += (float) ($item['gst']['sgst'] ?? 0);
                $total_gst += (float) ($item['gst']['igst'] ?? 0);
            }
        }

        if ($data['status'] == 0) {
            $response['is_deliverable_address'] = 0;
            $response['delivery_charge'] = 0;
            $response['surge_charges'] = [];
            $response['zone_additional_charges'] = [];
            $response['total_amount'] = $total->total_amount - $promo_discount;
        } else {
            $promoFreeDelivery = isset($promocode_details)
                && (int) ($promocode_details['is_applicable'] ?? 0) === 1
                && (int) ($promocode_details['free_delivery'] ?? 0) === 1;
            $free_delivery = ($request->get('is_free_delivery', 0) == 1) || $promoFreeDelivery;
            if ($free_delivery) {
                $data['data']['total_delivery_charge'] = 0;
                $data['data']['delivery_charge'] = 0;
                $data['data']['delivery_charge_tax'] = 0;
            }

            $surge_total      = (float) ($data['data']['surge_total'] ?? 0);
            $additional_total = (float) ($data['data']['additional_total'] ?? 0);

            $response['is_deliverable_address'] = 1;
            $response['delivery_charge'] = $data['data']['total_delivery_charge'];
            $response['surge_charges'] = $data['data']['surge_charges'] ?? [];
            $response['zone_additional_charges'] = $data['data']['additional_charges'] ?? [];
            $response['total_amount'] = $total->total_amount + $data['data']['total_delivery_charge']
                + $surge_total + $additional_total - $promo_discount;
        }

        $decimals = (int) (Setting::get_value('decimal_point') ?? 2);
        // Wallet balance for the cart's country (from the resolved zone).
        $response['user_balance'] = CommonHelper::getUserWalletBalance($user_id, $zone?->country_id);
        $response['sub_total'] = $sub_total;
        $response['saved_amount'] = number_format((float) $saved_amount, $decimals, '.', '');
        $response['total_gst'] = number_format($total_gst, $decimals, '.', '');
        $response['cart'] = $res;

        return CommonHelper::responseWithData($response, $total->cart_items_count);
    }

    /**
     * Cart recommendations: cross-sell + up-sell products for everything in the
     * user's cart. Each block has its own limit/offset and uses the same product
     * card shape as the cart. Products already in the cart are excluded.
     */
    public function getCartRecommendations(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'latitude'  => 'required',
            'longitude' => 'required',
        ]);
        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        $channel = strtolower(trim((string) $request->header('channel')));
        if (!in_array($channel, ['quick', 'ecommerce'], true)) {
            return CommonHelper::responseError(__('invalid_channel_header'));
        }

        // Optional single-product mode: when product_id is passed, recommendations
        // come from that product alone, so guests (no auth) are allowed. Cart mode
        // (no product_id) still requires the authenticated customer.
        $productId = (int) $request->input('product_id', 0);

        $user_id = $request->user('api-customers') ? $request->user('api-customers')->id : null;
        if (!$user_id && $productId <= 0) {
            return CommonHelper::responseError('no_items_found_in_users_cart');
        }

        $lat  = (float) $request->latitude;
        $lng  = (float) $request->longitude;
        $zone = CommonHelper::getDeliverableCity($lat, $lng, $channel);
        if (!$zone) {
            return CommonHelper::responseError(__('not_deliverable_to_this_location'));
        }

        // Zone stores (quick respects store hours) — drives product visibility + stock.
        $storeIds = Store::where('status', 1)
            ->where('zone_id', $zone->id)
            ->get()
            ->filter(fn($s) => $channel !== 'quick' || CommonHelper::isStoreOpenNow($s))
            ->pluck('id')->all();

        // Single-product mode: only that product's upsell recommendations,
        // cross_sell stays empty (cart ignored).
        if ($productId > 0) {
            $baseProductIds = [$productId];
        } else {
            // Cart product ids for this channel (legacy null-channel rows included).
            $baseProductIds = Cart::where('user_id', $user_id)
                ->where(function ($q) use ($channel) {
                    $q->where('channel', $channel)->orWhereNull('channel');
                })
                ->pluck('product_id')->map(fn($id) => (int) $id)->unique()->values()->all();

            if (empty($baseProductIds)) {
                return CommonHelper::responseError('no_items_found_in_users_cart');
            }
        }

        // Related product ids per type (union across the base products, excluding the
        // base products themselves).
        $recoRows = ProductRecommendation::whereIn('product_id', $baseProductIds)
            ->orderBy('row_order')->orderBy('id')
            ->get(['related_product_id', 'type']);

        // Single-product mode: cross_sell stays empty.
        $crossIds = $productId > 0 ? [] : $recoRows->where('type', ProductRecommendation::TYPE_CROSS_SELL)
            ->pluck('related_product_id')->map(fn($id) => (int) $id)->unique()
            ->reject(fn($id) => in_array($id, $baseProductIds, true))->values()->all();
        $upsellIds = $recoRows->where('type', ProductRecommendation::TYPE_UPSELL)
            ->pluck('related_product_id')->map(fn($id) => (int) $id)->unique()
            ->reject(fn($id) => in_array($id, $baseProductIds, true))->values()->all();

        $crossLimit  = (int) ($request->input('cross_sell_limit', 10));
        $crossOffset = (int) ($request->input('cross_sell_offset', 0));
        $upLimit     = (int) ($request->input('upsell_limit', 10));
        $upOffset    = (int) ($request->input('upsell_offset', 0));

        $delivery      = CustomerProductShaper::zoneDelivery($zone, $lat, $lng);
        $storeName     = $delivery['store_name'];
        $timeToDeliver = $channel === 'quick' ? (int) $delivery['time_to_deliver'] : 0;
        $currency      = CommonHelper::countryCurrency($zone?->country);

        $ctx = compact('storeIds', 'channel', 'user_id', 'storeName', 'timeToDeliver', 'currency');

        $response = [
            'cross_sell' => $this->buildRecommendationBlock($crossIds, $crossLimit, $crossOffset, $ctx),
            'upsell'     => $this->buildRecommendationBlock($upsellIds, $upLimit, $upOffset, $ctx),
        ];

        return CommonHelper::responseWithData($response);
    }

    /**
     * Shape a paginated set of recommended products (zone-visible) into the cart
     * card shape. Preserves the recommendation order. Returns
     * { products, total, limit, offset }.
     */
    private function buildRecommendationBlock(array $relatedIds, int $limit, int $offset, array $ctx): array
    {
        $limit  = $limit > 0 ? min($limit, 50) : 10;
        $offset = max(0, $offset);

        $storeIds = $ctx['storeIds'];
        if (empty($relatedIds) || empty($storeIds)) {
            return ['products' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset];
        }

        // Visible products in the zone (listed in a zone store), keeping reco order.
        $visibleIds = Product::query()
            ->where('status', 1)
            ->where('is_draft', 0)
            ->whereIn('sales_channel', [$ctx['channel'], 'both'])
            ->whereIn('id', $relatedIds)
            ->whereExists(function ($q) use ($storeIds) {
                $q->select(DB::raw(1))
                    ->from('product_variants as pv')
                    ->join('product_variant_store_stocks as pvss', 'pvss.product_variant_id', '=', 'pv.id')
                    ->whereColumn('pv.product_id', 'products.id')
                    ->whereIn('pvss.store_id', $storeIds)
                    ->where('pvss.is_listed', 1)
                    ->where('pvss.stock_status', 1)
                    ->where(function ($w) {
                        $w->whereRaw('pvss.is_unlimited_stock = 1')
                            ->orWhere('pvss.available', '>', 0);
                    });
            })
            ->pluck('id')->all();

        // Restore the original recommendation order.
        $order = array_flip($relatedIds);
        usort($visibleIds, fn($a, $b) => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));

        $total   = count($visibleIds);
        $pageIds = array_slice($visibleIds, $offset, $limit);
        if (empty($pageIds)) {
            return ['products' => [], 'total' => $total, 'limit' => $limit, 'offset' => $offset];
        }

        $products = Product::with([
            'variants',
            'variants.images',
            'variants.storeStocks' => fn($q) => $q->whereIn('store_id', $storeIds),
            'variants.attributeValues.attribute.translations',
            'variants.attributeValues.attributeValue.translations',
            'brand.translations',
            'category.translations',
            'tax',
            'ratings',
            'translations',
        ])->whereIn('id', $pageIds)->get()->keyBy('id');

        $favoriteIds = $ctx['user_id']
            ? Favorite::where('user_id', $ctx['user_id'])
                ->whereIn('product_id', $pageIds)
                ->pluck('product_id')->all()
            : [];

        $cards = [];
        foreach ($pageIds as $pid) {
            $p = $products[$pid] ?? null;
            if (!$p) {
                continue;
            }
            $cards[] = CustomerProductShaper::shapeCard(
                $p,
                $favoriteIds,
                $ctx['timeToDeliver'],
                $ctx['storeName'],
                $ctx['currency']
            );
        }

        return ['products' => $cards, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    public function addToCart(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required',
            'product_variant_id' => 'required',
            'qty' => 'required|numeric|min:1',
            'latitude' => 'required',
            'longitude' => 'required'
        ]);

        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        $product_id = $request->product_id;
        $variant_id = $request->product_variant_id;
        $qty = $request->input('qty', '');
        $user = auth()->user();
        $channel = strtolower(trim((string) $request->header('channel')));
        if (!in_array($channel, ['quick', 'ecommerce'], true)) {
            $channel = null;
        }
        $lat = $request->filled('latitude') ? (float) $request->latitude : null;
        $lng = $request->filled('longitude') ? (float) $request->longitude : null;
        $store_id = ProductHelper::resolveCartStore($variant_id, $lat, $lng, $channel);

        // Quick commerce can't accept orders while the store is closed — block the
        // add-to-cart (ecommerce ignores store hours).
        if ($channel === 'quick' && $store_id) {
            $store = Store::find($store_id);
            if ($store && !CommonHelper::isStoreOpenNow($store)) {
                return CommonHelper::responseError(__('store_is_closed'));
            }
        }

        if (ProductHelper::isItemAvailable($product_id, $variant_id)) {
            if (ProductHelper::isItemAvailableWithStock($product_id, $variant_id, $qty, $store_id)) {

                $variant = ProductVariant::from('product_variants as pv')->where('id', $variant_id)->first();

                if ($variant) {

                    if (ProductHelper::isVariantUnlimited($variant_id, $store_id) || ProductHelper::storeAvailableStock($variant_id, $store_id) > 0) {

                        if (ProductHelper::isItemAvailableInUserCart($user->id, $variant_id, $channel)) {
                            $cart = Cart::where('user_id', $user->id)
                                ->where('product_variant_id', $variant_id)
                                ->where(function ($q) use ($channel) {
                                    if (in_array($channel, ['quick', 'ecommerce'], true)) {
                                        $q->where('channel', $channel)->orWhereNull('channel');
                                    }
                                })
                                ->first();

                            $total_quantity = Cart::where('user_id', $user->id)
                                ->where('product_id', $product_id)
                                ->sum('qty');

                            if ($total_quantity) {
                                $total_allowed_quantity = Product::where('id', $product_id)->pluck('total_allowed_quantity')->first();

                                $temp = Cart::where('user_id', $user->id)->where('product_variant_id', $variant_id)->pluck('qty')->first();

                                $total_quantity = $total_quantity - $temp;
                                $total_quantity = $total_quantity + $qty;

                                // total_allowed_quantity = 0 means no limit — only enforce when > 0.
                                if ($total_allowed_quantity > 0 && $total_quantity > $total_allowed_quantity) {
                                    return CommonHelper::responseError('maximum_products_quantity_limit_reached_message');
                                }
                            }

                            if ($cart) {
                                $oldQty = (int) $cart->qty;
                                $delta  = (int) $qty - $oldQty;
                                if ($delta > 0
                                    && !ProductHelper::isVariantUnlimited($variant_id, $cart->store_id)
                                    && ProductHelper::storeAvailableStock($variant_id, $cart->store_id) < $delta) {
                                    return CommonHelper::responseError('opps_stock_is_not_available');
                                }

                                $cart->qty = $qty;
                                if ($channel !== null && empty($cart->channel)) {
                                    $cart->channel = $channel;
                                }
                                $cart->save();

                                if ($delta > 0) {
                                    ProductHelper::reserveStock($variant_id, $cart->store_id, $delta);
                                } elseif ($delta < 0) {
                                    ProductHelper::releaseStock($variant_id, $cart->store_id, -$delta);
                                }

                                return $this->buildUserCartResponse($request, $user->id);
                            } else {
                                return CommonHelper::responseError('item_not_found');
                            }
                        } else {

                            if ($user->status == 1) {

                                $total_allowed_quantity = Product::where('id', $product_id)->value('total_allowed_quantity');
                                if ($total_allowed_quantity && $qty > $total_allowed_quantity) {
                                    return CommonHelper::responseError('maximum_products_quantity_limit_reached_message');
                                }

                                if (!ProductHelper::isVariantUnlimited($variant_id, $store_id)
                                    && ProductHelper::storeAvailableStock($variant_id, $store_id) < (int) $qty) {
                                    return CommonHelper::responseError('opps_stock_is_not_available');
                                }

                                $data = array(
                                    'user_id' => $user->id,
                                    'product_id' => $product_id,
                                    'product_variant_id' => $variant_id,
                                    'store_id' => $store_id,
                                    'channel' => $channel,
                                    'qty' => $qty,
                                    'created_at' => date('Y-m-d H:i:s')
                                );
                                $insert = Cart::insert($data);
                                if ($insert) {
                                    ProductHelper::reserveStock($variant_id, $store_id, (int) $qty);
                                    return $this->buildUserCartResponse($request, $user->id);
                                } else {
                                    return CommonHelper::responseError('something_went_wrong');
                                }
                            } else {
                                return CommonHelper::responseError('not_allowed_to_add_to_cart_as_your_account_is_de_activated');
                            }
                        }
                    } else {
                        return CommonHelper::responseError('opps_stock_is_not_available');
                    }
                } else {
                    return CommonHelper::responseError('no_such_item_available');
                }
            } else {
                return CommonHelper::responseError('opps_stock_is_not_available');
            }
        } else {
            return CommonHelper::responseError('no_such_item_available');
        }
    }

    public function removeFromCart(Request $request)
    {
        $user_id = auth()->user()->id;
        $variant_id = $request->input('product_variant_id', '');
        if (ProductHelper::isItemAvailableInUserCart($user_id, $variant_id)) {
            $cart = Cart::where('user_id', $user_id);

            if (!empty($variant_id)) {
                $cartRow = Cart::where('user_id', $user_id)
                    ->where('product_variant_id', $variant_id)
                    ->first();

                if (!$cartRow) {
                    return CommonHelper::responseError('no_product_found');
                }

                $removeQty = max(1, (int) $request->input('qty', 1));
                $removeQty = min($removeQty, (int) $cartRow->qty);

                if ($cartRow->qty - $removeQty > 0) {
                    $cartRow->decrement('qty', $removeQty);
                    ProductHelper::releaseStock($cartRow->product_variant_id, $cartRow->store_id, $removeQty);
                } else {
                    ProductHelper::releaseStock($cartRow->product_variant_id, $cartRow->store_id, (int) $cartRow->qty);
                    $cartRow->delete();
                }

                return $this->buildUserCartResponse($request, $user_id);
            } else if (isset($request->is_remove_all) && $request->is_remove_all == 1) {
                // Clear only the requested channel's rows (same set the cart view shows:
                // channel match OR legacy null-channel rows). No/invalid header => clear all.
                $channel = strtolower(trim((string) $request->header('channel')));
                if (in_array($channel, ['quick', 'ecommerce'], true)) {
                    $cart->where(function ($q) use ($channel) {
                        $q->where('channel', $channel)->orWhereNull('channel');
                    });
                }
                $removing = (clone $cart)->get(['product_variant_id', 'store_id', 'qty']);
                $cart = $cart->delete();
                if ($cart) {
                    foreach ($removing as $row) {
                        ProductHelper::releaseStock($row->product_variant_id, $row->store_id, (int) $row->qty);
                    }
                    return CommonHelper::responseSuccess('all_items_removed_from_users_cart_successfully');
                } else {
                    return CommonHelper::responseError('no_product_found');
                }
            } else {
                return CommonHelper::responseError('no_items_found_in_users_cart');
            }
        } else {
            return CommonHelper::responseError('no_items_found_in_users_cart');
        }
    }

    public function getGuestCart(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'latitude' => 'required',
            'longitude' => 'required',
        ], [
            'latitude.required' => 'The latitude field is required.',
            'longitude.required' => 'The longitude field is required.'
        ]);

        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        $variant_id = explode(",", $request->variant_ids);
        $quantity = explode(",", $request->quantities);
        if (count($variant_id) === count($quantity)) {

            $channel = strtolower(trim((string) $request->header('channel')));
            if (!in_array($channel, ['quick', 'ecommerce'], true)) {
                $channel = null;
            }

            $zone = CommonHelper::getDeliverableCity($request->latitude, $request->longitude, $channel);

            $variantStore   = [];
            foreach ($variant_id as $vid) {
                $vid = (int) $vid;
                $variantStore[$vid] = ProductHelper::resolveCartStore($vid, $request->latitude, $request->longitude, $channel);
            }
            $store_ids = array_values(array_filter(array_unique(array_values($variantStore))));

            $variantProduct = ProductVariant::whereIn('id', $variant_id)->pluck('product_id', 'id');
            $product_ids    = array_values(array_unique($variantProduct->all()));

            $storeInfo = Store::whereIn('id', $store_ids ?: [0])->get(['id', 'name', 'latitude', 'longitude'])->keyBy('id');

            $products = Product::with([
                'variants',
                'variants.images',
                'variants.storeStocks' => fn($q) => $q->whereIn('store_id', $store_ids ?: [0]),
                'variants.attributeValues.attribute.translations',
                'variants.attributeValues.attributeValue.translations',
                'brand.translations',
                'category.translations',
                'tax',
                'ratings',
                'translations',
            ])->whereIn('id', $product_ids)->get()->keyBy('id');

            $res = [];
            foreach ($variant_id as $idx => $vid) {
                $vid = (int) $vid;
                $pid = $variantProduct[$vid] ?? null;
                if (!$pid) {
                    continue;
                }
                $product = $products[$pid] ?? null;
                if (!$product) {
                    continue;
                }
                $qty = (int) ($quantity[$idx] ?? 0);

                $sid       = $variantStore[$vid] ?? null;
                $store     = $sid ? ($storeInfo[$sid] ?? null) : null;
                $storeName = $store ? $store->name : null;

                $timeToDeliver = 0;
                if ($channel === 'quick' && $zone && $store && $store->latitude !== null && $store->longitude !== null) {
                    $perUnit = (float) ($zone->travel_time_per_km ?? 0);
                    if ($perUnit > 0) {
                        $km = CommonHelper::osrmDistanceKm($store->latitude, $store->longitude, $request->latitude, $request->longitude);
                        $dist = CommonHelper::convertKmToUnit($km, $zone->distance_unit ?? 'km');
                        $timeToDeliver = $dist !== null ? (int) ceil($dist * $perUnit) : 0;
                    }
                }

                $res[] = CustomerProductShaper::shapeCartItem(
                    $product, 
                    $vid, 
                    $qty, 
                    [], 
                    $timeToDeliver, 
                    $storeName, 
                    CommonHelper::countryCurrency($zone?->country), 
                    $sid ? (int) $sid : null, 
                    $store ? ($store->state ?? 'Maharashtra') : 'Maharashtra',  // Store state
                    $request->input('address_state') ?? ($zone ? $zone->state : 'Maharashtra')  // Customer state
                );
            }

            if (!empty($res)) {

                $total = CommonHelper::getGuestCartCount($variant_id, $quantity);
                $sub_total = $total['sub_total']; // Use base price without tax

                $saved_amount = max(0, $total['save_price'] - $total['total_amount']);

                $response = [];

                // ---- COD availability (country-wise, from countries.payment_gateways) ----
                $response['cod_allowed'] = CommonHelper::isCodAllowed($zone?->country, $res);

                $response['product_variant_id'] = $total['product_variant_id'];
                $response['quantity'] = $total['quantity'];

                // ---- Store -> customer distance ----
                $distance = null;
                $distance_unit = $zone ? CommonHelper::normalizeDistanceUnit($zone->distance_unit ?? 'km') : 'km';
                foreach ($storeInfo as $store) {
                    if ($store->latitude === null || $store->longitude === null) {
                        continue;
                    }
                    $km = CommonHelper::roadDistanceKm($request->latitude, $request->longitude, $store->latitude, $store->longitude);
                    $dist = CommonHelper::convertKmToUnit($km, $distance_unit);
                    if ($dist !== null && ($distance === null || $dist < $distance)) {
                        $distance = $dist;
                    }
                }
                $response['distance'] = $distance !== null ? round($distance, 2) . ' ' . $distance_unit : null;

                $minimum_order_amount = 0;
                if ($channel === 'quick' && $zone) {
                    $minimum_order_amount = (float) $zone->minimum_order_amount;
                }
                $response['minimum_order_amount'] = $minimum_order_amount;

                $currency = CommonHelper::countryCurrency($zone?->country);
                $response['currency'] = $currency['currency'];
                $response['currency_code'] = $currency['currency_code'];
                $response['decimal_point'] = $currency['decimal_point'];

                // ---- Promo nudge (guest: user_id null) ----
                $nudge = CommonHelper::getCartPromoNudge(null, $sub_total, (int) ($total['cart_total_qty'] ?? 0), [
                    'latitude'  => $request->latitude ?? null,
                    'longitude' => $request->longitude ?? null,
                    'channel'   => $channel,
                    'platform'  => strtolower(trim((string) $request->input('platform'))) ?: null,
                ]);
                $response['unlock_message'] = $nudge['unlock_message'];
                $response['unlock_promo_code'] = $nudge['unlock_promo_code'];
                $response['unlock_promo_code_id'] = $nudge['unlock_promo_code_id'];

                // ---- Delivery charge ----
                $data = CommonHelper::getAllDeliveryCharge($request->latitude, $request->longitude, $store_ids, $sub_total, $channel);
                if ($data['status'] == 0) {
                    $response['is_deliverable_address'] = 0;
                    $response['delivery_charge'] = 0;
                    $response['surge_charges'] = [];
                    $response['zone_additional_charges'] = [];
                    $response['total_amount'] = $sub_total;
                } else {
                    $free_delivery = $request->get('is_free_delivery', 0);
                    if ($free_delivery == 1) {
                        $data['data']['total_delivery_charge'] = 0;
                        $data['data']['delivery_charge'] = 0;
                        $data['data']['delivery_charge_tax'] = 0;
                    }

                    $surge_total      = (float) ($data['data']['surge_total'] ?? 0);
                    $additional_total = (float) ($data['data']['additional_total'] ?? 0);

                    $response['is_deliverable_address'] = 1;
                    $response['delivery_charge'] = $data['data']['total_delivery_charge'];
                    $response['surge_charges'] = $data['data']['surge_charges'] ?? [];
                    $response['zone_additional_charges'] = $data['data']['additional_charges'] ?? [];
                    $response['total_amount'] = $sub_total + $data['data']['total_delivery_charge']
                        + $surge_total + $additional_total;
                }

                $decimals = (int) (Setting::get_value('decimal_point') ?? 2);
                $response['user_balance'] = 0;
                $response['sub_total'] = $sub_total;
                $response['saved_amount'] = number_format((float) $saved_amount, $decimals, '.', '');
                $response['cart'] = $res;

                return CommonHelper::responseWithData($response, $total['cart_items_count']);
            } else {
                return CommonHelper::responseError('no_items_found_in_users_cart');
            }
        } else {
            return CommonHelper::responseError('variant_and_quantity_does_not_match');
        }
    }
    public function BulkAddToCartItems(Request $request)
    {
        $user = auth()->user();
        $lat = $request->filled('latitude') ? (float) $request->latitude : null;
        $lng = $request->filled('longitude') ? (float) $request->longitude : null;

        // Two independent channel baskets can be sent in one call: quick_* and
        // ecommerce_*. Either or both may be present; each *_variant_ids requires its
        // matching *_quantities.
        $sets = [];
        foreach (['quick', 'ecommerce'] as $ch) {
            $rawIds = (string) $request->input($ch . '_variant_ids', '');
            if (trim($rawIds) === '') {
                continue;
            }
            $rawQty = $request->input($ch . '_quantities');
            if ($rawQty === null || trim((string) $rawQty) === '') {
                return CommonHelper::responseError($ch . '_quantities_is_required');
            }
            $variant_ids = explode(',', $rawIds);
            $quantities  = explode(',', (string) $rawQty);
            if (count($variant_ids) !== count($quantities)) {
                return CommonHelper::responseError('mismatched_variants_and_quantities');
            }
            $sets[$ch] = ['variant_ids' => $variant_ids, 'quantities' => $quantities];
        }

        if (empty($sets)) {
            return CommonHelper::responseError('variant_and_quantity_does_not_match');
        }

        $available_products = [];
        $out_of_stock_products = [];
        foreach ($sets as $ch => $set) {
            // Quick commerce can't accept items while the store is closed.
            if ($ch === 'quick') {
                $quickZone = CommonHelper::getDeliverableCity($lat, $lng, 'quick');
                $quickStore = $quickZone ? Store::where('zone_id', $quickZone->id)->where('status', 1)->first() : null;
                if ($quickStore && !CommonHelper::isStoreOpenNow($quickStore)) {
                    return CommonHelper::responseError(__('store_is_closed'));
                }
            }
            $err = $this->bulkAddChannelItems(
                $user,
                $set['variant_ids'],
                $set['quantities'],
                $ch,
                $lat,
                $lng,
                $available_products,
                $out_of_stock_products
            );
            if ($err !== null) {
                return $err;
            }
        }

        $available_products_names = implode(', ', array_column($available_products, 'product_name'));
        $out_of_stock_products_names = implode(', ', array_column($out_of_stock_products, 'product_name'));

        $countChannel = count($sets) === 1 ? array_key_first($sets) : null;
        $total = CommonHelper::getCartCount($user->id, $countChannel);
        $sub_total = $total->sub_total; // Base price without tax
        $saved_amount = $total->save_price - $total->total_amount;
        $saved_amount = ($saved_amount <= 0) ? 0 : $saved_amount;
        if (!empty($available_products_names)) {

            return Response::json([
                'status' => 1,
                'message' => 'items_added_to_users_cart_successfully',
                'cart_items_count' => $total->cart_items_count,
                'cart_total_qty' => $total->cart_total_qty,
                'sub_total' => $sub_total,
                'saved_amount' => $saved_amount,
                'available_products_names' => $available_products_names,
                'out_of_stock_products_names' => $out_of_stock_products_names,
            ]);
        } else {
            return Response::json([
                'status' => 0,
                'message' => 'Items not available',
                'cart_items_count' => $total->cart_items_count,
                'cart_total_qty' => $total->cart_total_qty,
                'sub_total' => $sub_total,
                'saved_amount' => $saved_amount,
                'available_products_names' => $available_products_names,
                'out_of_stock_products_names' => $out_of_stock_products_names,
            ]);
        }
    }

    /**
     * Add one channel's basket (quick or ecommerce) to the user's cart. Appends to
     * $available_products / $out_of_stock_products by reference. Returns null on
     * success, or an error JsonResponse to bubble up on a hard failure.
     */
    private function bulkAddChannelItems($user, array $variant_ids, array $quantities, string $channel, $lat, $lng, array &$available_products, array &$out_of_stock_products)
    {
        foreach ($variant_ids as $index => $variant_id) {
            $qty = $quantities[$index];
            $variant = ProductVariant::from('product_variants as pv')->where('id', $variant_id)->first();

            if (!$variant) {
                return CommonHelper::responseError('no_such_item_available');
            }

            $product_id = $variant->product_id;
            $product = Product::find($product_id);

            if (!$product) {
                return CommonHelper::responseError('product_not_found');
            }

            $variant_store_id = ProductHelper::resolveCartStore($variant_id, $lat, $lng, $channel);

            if (ProductHelper::isItemAvailableWithStock($product_id, $variant_id, $qty, $variant_store_id)) {
                $available_products[] = [
                    'product_id'   => $product_id,
                    'variant_id'   => $variant_id,
                    'product_name' => $product->name,
                ];
                if (ProductHelper::isItemAvailableInUserCart($user->id, $variant_id, $channel)) {
                    $cart = Cart::where('user_id', $user->id)
                        ->where('product_variant_id', $variant_id)
                        ->where(function ($q) use ($channel) {
                            if (in_array($channel, ['quick', 'ecommerce'], true)) {
                                $q->where('channel', $channel)->orWhereNull('channel');
                            }
                        })
                        ->first();

                    if ($cart) {
                        $total_quantity = Cart::where('user_id', $user->id)
                            ->where('product_id', $product_id)
                            ->sum('qty');

                        $total_allowed_quantity = Product::where('id', $product_id)->pluck('total_allowed_quantity')->first();
                        $current_quantity = Cart::where('user_id', $user->id)->where('product_variant_id', $variant_id)->pluck('qty')->first();
                        $total_quantity = $total_quantity - $current_quantity + $qty;

                        if ($total_quantity > $total_allowed_quantity) {
                            return CommonHelper::responseError('total_allowed_quantity_for_this_product_is_reached');
                        }

                        $oldQty = (int) $cart->qty;
                        $delta  = (int) $qty - $oldQty;
                        $cart->qty = $qty;
                        if ($channel !== null && empty($cart->channel)) {
                            $cart->channel = $channel;
                        }
                        $cart->save();
                        if ($delta > 0) {
                            ProductHelper::reserveStock($variant_id, $cart->store_id, $delta);
                        } elseif ($delta < 0) {
                            ProductHelper::releaseStock($variant_id, $cart->store_id, -$delta);
                        }
                    } else {
                        return CommonHelper::responseError('item_not_found');
                    }
                } else {
                    if ($user->status == 1) {
                        $total_allowed_quantity = Product::where('id', $product_id)->pluck('total_allowed_quantity')->first();
                        if ($total_allowed_quantity && $qty > $total_allowed_quantity) {
                            return CommonHelper::responseError('total_allowed_quantity_for_this_product_is_reached');
                        }

                        $data = [
                            'user_id' => $user->id,
                            'product_id' => $product_id,
                            'product_variant_id' => $variant_id,
                            'store_id' => $variant_store_id,
                            'channel' => $channel,
                            'qty' => $qty,
                            'created_at' => date('Y-m-d H:i:s')
                        ];

                        Cart::insert($data);
                        ProductHelper::reserveStock($variant_id, $variant_store_id, (int) $qty);
                    } else {
                        return CommonHelper::responseError('not_allowed_to_add_to_cart_as_your_account_is_de_activated');
                    }
                }
            } else {
                $out_of_stock_products[] = ['product_id' => $product_id, 'variant_id' => $variant_id, 'product_name' => $product->name];
            }
        }

        return null;
    }
}
