<?php

namespace App\Helpers;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Store;

/**
 * Shared product-card shaper for customer-facing endpoints.
 * Used by Customer\ProductsApiController + HomeLayoutResolver so listings
 * and home-builder product blocks return an identical payload.
 *
 * Required eager loads on $p: variants, variants.images, variants.storeStocks
 * (scoped to the zone's storeIds), variants.attributeValues.attribute,
 * variants.attributeValues.attributeValue, category, brand, tax, ratings.
 */
class CustomerProductShaper
{
    public static function buildVariantImages(ProductVariant $variant): array
    {
        $images = $variant->images->map(fn ($im) => [
            'image_url' => $im->image_url,
        ])->values()->all();

        if (!empty($variant->image)) {
            array_unshift($images, [
                'image_url' => $variant->image_url,
            ]);
        }
        return $images;
    }

    /**
     * Zone delivery info: the zone's store name and minutes to deliver (quick
     * commerce). One zone has one store, so this is the same for every product in
     * the zone. `time_to_deliver` = straight-line km from the store to the customer
     * times the zone's travel_time_per_km; 0 when no store coords / per-km time.
     * Returns ['store_name' => ?string, 'time_to_deliver' => int].
     */
    public static function zoneDelivery($zone, $custLat, $custLng): array
    {
        $out = ['store_name' => null, 'time_to_deliver' => 0, 'distance' => null, 'distance_unit' => 'km'];
        if (!$zone) {
            return $out;
        }
        $store = Store::where('status', 1)
            ->where('zone_id', $zone->id)
            ->first(['name', 'latitude', 'longitude']);
        if (!$store) {
            return $out;
        }
        $out['store_name'] = (string) $store->name;

        $unit    = CommonHelper::normalizeDistanceUnit($zone->distance_unit ?? 'km');
        $out['distance_unit'] = $unit;

        if ($store->latitude !== null && $store->longitude !== null) {
            $km   = CommonHelper::straightLineDistanceKm($store->latitude, $store->longitude, $custLat, $custLng);
            $dist = CommonHelper::convertKmToUnit($km, $unit);
            if ($dist !== null) {
                $out['distance'] = round($dist, 2);
            }

            $perUnit = (float) ($zone->travel_time_per_km ?? 0);
            if ($perUnit > 0 && $dist !== null) {
                $out['time_to_deliver'] = (int) ceil($dist * $perUnit);
            }
        }
        return $out;
    }

    /**
     * Human-readable delivery time from minutes.
     * $short=true  → "5 mins", "1 hr", "1 hr 30 mins"   (products)
     * $short=false → "5 minutes", "1 hour", "1 hour 30 minutes" (cart / home layout)
     * Returns '' for 0/negative.
     */
    public static function formatDeliveryTime(int $minutes, bool $short = true): string
    {
        if ($minutes <= 0) {
            return '';
        }
        $hours = intdiv($minutes, 60);
        $mins  = $minutes % 60;

        $parts = [];
        if ($hours > 0) {
            $parts[] = $short
                ? $hours . ' ' . ($hours === 1 ? 'hr'  : 'hrs')
                : $hours . ' ' . ($hours === 1 ? 'hour' : 'hours');
        }
        if ($mins > 0) {
            $parts[] = $short
                ? $mins . ' ' . ($mins === 1 ? 'min'  : 'mins')
                : $mins . ' ' . ($mins === 1 ? 'minute' : 'minutes');
        }
        return implode(' ', $parts);
    }

    public static function shapeCard(Product $p, array $favoriteIds, int $timeToDeliver = 0, ?string $storeName = null, ?array $currency = null, ?int $representativeVariantId = null): array
    {
        // Price is per-store (PVSS). storeStocks is eager-loaded already scoped to the
        // zone's store(s); copy that store's pricing onto each variant (no store → the
        // cheapest listed row, i.e. the "from" price) so all reads below are store-priced.
        foreach ($p->variants as $vv) {
            ProductHelper::applyStorePrice($vv);
        }

        $listedVariants = $p->variants->filter(function ($v) {
            return $v->storeStocks->where('is_listed', 1)->count() > 0;
        });

        $cheapest = $listedVariants
            ->sortBy(fn ($v) => $v->discounted_price > 0 ? (float) $v->discounted_price : (float) $v->price)
            ->first();

        // The representative variant (data shown outside `variants`) is the one
        // pinned by the caller (e.g. the variant that matched a filter), falling
        // back to the cheapest listed variant.
        $variant = $representativeVariantId !== null
            ? ($listedVariants->firstWhere('id', $representativeVariantId) ?: $cheapest)
            : $cheapest;

        $taxPct       = $p->tax ? (float) $p->tax->percentage : 0;
        $priceRaw     = $variant ? (float) $variant->price : 0;
        $discRaw      = $variant ? (float) $variant->discounted_price : 0;
        $priceWithTax = $priceRaw * (1 + $taxPct / 100);
        $discWithTax  = $discRaw > 0 ? $discRaw * (1 + $taxPct / 100) : 0;
        $finalPrice   = $discWithTax > 0 ? $discWithTax : $priceWithTax;

        $discountPct = ($priceWithTax > 0 && $discWithTax > 0)
            ? (int) round((($priceWithTax - $discWithTax) / $priceWithTax) * 100)
            : 0;

        $productUnlimited = 0;
        $defaultStock = 0;
        $defaultInStock = false;
        $defaultIsMinAlert = false;
        if ($variant) {
            $listedStocks = $variant->storeStocks->where('is_listed', 1);
            $hasListedStore = $listedStocks->count() > 0;
            $hasInStockStore = $listedStocks->where('stock_status', 1)->count() > 0;
            $productUnlimited = self::unlimitedFromStocks($variant->storeStocks);
            $defaultStock = (int) $listedStocks->where('stock_status', 1)->sum('available');
            $defaultInStock = $hasListedStore
                && ($productUnlimited === 1 || ($hasInStockStore && $defaultStock > 0));
            $defaultMinAlert = (int) $listedStocks->sum('min_alert');
            $defaultIsMinAlert = $productUnlimited === 0
                && $hasListedStore
                && $defaultStock > 0
                && $defaultStock <= $defaultMinAlert;
        }

        $representativeId = $variant ? $variant->id : null;
        $variantsPayload = $listedVariants
            ->sortBy(fn ($v) => $v->id === $representativeId ? 0 : 1)
            ->map(fn ($v) => self::shapeVariant($v, $taxPct))
            ->values()->all();

        $price           = (float) CommonHelper::doubleNumber($priceWithTax);
        $discountedPrice = (float) CommonHelper::doubleNumber($finalPrice);

        // min_price is the lowest final price across all listed variants — kept
        // independent of the representative variant so pinning a non-cheapest
        // variant (e.g. a filter match) still shows the true "from" price.
        $minPrice = $listedVariants->isNotEmpty()
            ? (float) CommonHelper::doubleNumber($listedVariants->map(function ($v) use ($taxPct) {
                $pw = (float) $v->price * (1 + $taxPct / 100);
                $dw = (float) $v->discounted_price > 0 ? (float) $v->discounted_price * (1 + $taxPct / 100) : 0;
                return $dw > 0 ? $dw : $pw;
            })->min())
            : $price;

        return [
            'id'                 => (int) $p->id,
            'name'               => $variant ? (string) $variant->name : '',
            'product_name'       => (string) ($p->name ?? ''),
            'slug'               => (string) $p->slug,
            'images'             => $variant ? self::buildVariantImages($variant) : [],
            'short_description'  => (string) ($p->short_description ?? ''),
            'product_type'       => (int) ($p->product_type ?? 0),
            'is_prescription_required' => (int) ($p->is_prescription_required ?? 0),
            'sales_channel'      => $p->sales_channel,
            'cod_allowed'        => (int) ($p->cod_allowed ?? 0),
            'category_id'        => (int) $p->category_id,
            'category_name'      => $p->category ? (string) $p->category->name : null,
            'brand_id'           => $p->brand_id ? (int) $p->brand_id : null,
            'brand_name'         => $p->brand ? (string) $p->brand->name : null,
            'is_unlimited_stock' => $productUnlimited,
            'total_allowed_quantity' => (int) ($p->total_allowed_quantity ?? 0),
            'in_stock'           => $defaultInStock,
            'is_min_alert'       => $defaultIsMinAlert,
            'is_favorite'        => in_array((int) $p->id, array_map('intval', $favoriteIds), true),
            'product_rating'     => (int) (Setting::get_value('product_rating') ?? 0) === 1,
            'rating'             => round((float) $p->ratings->avg('rate'), 1),
            'rating_count'       => $p->ratings->count(),
            'try_and_buy'        => (int) ($p->try_and_buy ?? 0),
            'try_and_buy_text'   => (string) ($p->try_and_buy_text ?? ''),
            'is_preorder_only'   => (int) ($p->is_preorder_only ?? 0),
            'preorder_info_text' => (string) ($p->preorder_info_text ?? ''),
            'variants_count'     => $listedVariants->count(),
            'min_price'          => $minPrice,
            'discount_percent'   => $discountPct,
            'variant_id'         => $variant ? (int) $variant->id : null,
            'sku'                => $variant ? $variant->sku : null,
            'price'              => $price,
            'discounted_price'   => $discountedPrice,
            'stock'              => $defaultStock,
            'store_name'         => $storeName,
            'time_to_deliver'    => self::formatDeliveryTime($timeToDeliver),
            'currency'           => $currency['currency'] ?? null,
            'currency_code'      => $currency['currency_code'] ?? null,
            'decimal_point'      => $currency['decimal_point'] ?? null,
            'variants'           => $variantsPayload,
        ];
    }

    /**
     * Cart-card shape: identical schema to shapeCard(), but driven by the cart line's
     * specific variant instead of the cheapest one. The `variants` array holds ONLY
     * that cart variant, and the variant carries a `quantity` (the cart qty).
     *
     * Eager loads on $p: same as shapeCard, but `variants.storeStocks` should be
     * scoped to the cart line's store (so stock reflects that store).
     */
    public static function shapeCartItem(Product $p, int $variantId, int $qty, array $favoriteIds, int $timeToDeliver = 0, ?string $storeName = null, ?array $currency = null, ?int $storeId = null, ?string $companyState = null, ?string $customerState = null): array
    {
        $variant = $p->variants->firstWhere('id', $variantId);
        // Price is per-store (PVSS): price by the cart line's exact fulfilling store.
        if ($variant) {
            ProductHelper::applyStorePrice($variant, $storeId);
        }

        $taxPct       = $p->tax ? (float) $p->tax->percentage : 0;
        $priceRaw     = $variant ? (float) $variant->price : 0;
        $priceWithTax = $priceRaw * (1 + $taxPct / 100);
        // Slab-aware effective unit price for the cart quantity.
        $effUnit      = $variant ? ProductHelper::slabUnitPrice($variant, $qty > 0 ? $qty : 1) : 0;
        $finalPrice   = $effUnit * (1 + $taxPct / 100);

        $discountPct = ($priceWithTax > 0 && $finalPrice > 0 && $finalPrice < $priceWithTax)
            ? (int) round((($priceWithTax - $finalPrice) / $priceWithTax) * 100)
            : 0;

        $productUnlimited = 0;
        $stock = 0;
        $inStock = false;
        $isMinAlert = false;
        if ($variant) {
            $scopedStocks = $storeId !== null
                ? $variant->storeStocks->where('store_id', $storeId)
                : $variant->storeStocks;
            $listedStocks = $scopedStocks->where('is_listed', 1);
            $hasListedStore = $listedStocks->count() > 0;
            $productUnlimited = self::unlimitedFromStocks($scopedStocks);

            $stock = (int) $listedStocks->where('stock_status', 1)->sum('available');

            $ownReserved = $storeId !== null ? max(0, (int) $qty) : 0;
            $stockForCart = (int) $listedStocks->sum('available') + $ownReserved;
            $inStock = $hasListedStore && ($productUnlimited === 1 || $stockForCart > 0);

            $minAlert = (int) $listedStocks->sum('min_alert');
            $isMinAlert = $productUnlimited === 0 && $hasListedStore && $stock > 0 && $stock <= $minAlert;
        }

        // Upsell message: nearest higher-qty slab that beats the current unit price.
        // Only shown when the store can actually fulfil the extra units — `available`
        // already excludes what's reserved, so we need available >= the units to add.
        // Unlimited-stock products always qualify. Empty string otherwise.
        $slabDiscountMessage = '';
        if ($variant) {
            $hint = ProductHelper::nextSlabHint($variant, $qty > 0 ? $qty : 1, $taxPct);
            $canAddMore = $productUnlimited === 1 || ($hint && $stock >= (int) $hint['add_qty']);
            if ($hint && $canAddMore) {
                $cur = $currency['currency'] ?? '';
                $slabDiscountMessage = str_replace(
                    [':qty', ':price'],
                    [$hint['add_qty'], $cur . $hint['price']],
                    __('slab_upsell_message')
                );
            }
        }

        // Only the cart variant is returned, with its quantity.
        $variantPayload = [];
        if ($variant) {
            $variantPayload[] = self::shapeVariant($variant, $taxPct, (int) $qty, $storeId);
        }

        // NOTE: GST system is SEPARATE from the old tax system
        // Use slab-aware base price WITHOUT old tax for GST calculations
        $slabBasePrice = $variant ? ProductHelper::slabUnitPrice($variant, $qty > 0 ? $qty : 1) : 0;
        
        $price           = (float) CommonHelper::doubleNumber($priceWithTax);
        $discountedPrice = (float) CommonHelper::doubleNumber($finalPrice);

        // Task #6: Calculate per-item GST (CGST+SGST for intra-state, IGST for inter-state)
        // Pass the slab-aware BASE price (without old tax), as GST will be calculated on top
        $gstBreakdown = self::calculateItemGST($p, $qty, $slabBasePrice, $companyState, $customerState);

        // NOTE: Do NOT add GST to discountedPrice here!
        // calculateTotalAmount() will handle GST addition based on gst_inclusive flag.
        // Adding GST here would cause double-calculation.

        return [
            'id'                 => (int) $p->id,
            'name'               => $variant ? (string) $variant->name : '',
            'product_name'       => (string) ($p->name ?? ''),
            'slug'               => (string) $p->slug,
            'images'             => $variant ? self::buildVariantImages($variant) : [],
            'short_description'  => (string) ($p->short_description ?? ''),
            'product_type'       => (int) ($p->product_type ?? 0),
            'is_prescription_required' => (int) ($p->is_prescription_required ?? 0),
            'sales_channel'      => $p->sales_channel,
            'cod_allowed'        => (int) ($p->cod_allowed ?? 0),
            'category_id'        => (int) $p->category_id,
            'category_name'      => $p->category ? (string) $p->category->name : null,
            'brand_id'           => $p->brand_id ? (int) $p->brand_id : null,
            'brand_name'         => $p->brand ? (string) $p->brand->name : null,
            'is_unlimited_stock' => $productUnlimited,
            'total_allowed_quantity' => (int) ($p->total_allowed_quantity ?? 0),
            'in_stock'           => $inStock,
            'is_min_alert'       => $isMinAlert,
            'is_favorite'        => in_array((int) $p->id, array_map('intval', $favoriteIds), true),
            'rating'             => round((float) $p->ratings->avg('rate'), 1),
            'rating_count'       => $p->ratings->count(),
            'variants_count'     => 1,
            'min_price'          => $price,
            'discount_percent'   => $discountPct,
            'variant_id'         => $variant ? (int) $variant->id : null,
            'sku'                => $variant ? $variant->sku : null,
            'price'              => $price,
            'discounted_price'   => $discountedPrice,
            'slab_discount_message' => $slabDiscountMessage,
            'stock'              => $stock,
            'store_name'         => $storeName,
            'time_to_deliver'    => self::formatDeliveryTime($timeToDeliver),
            'currency'           => $currency['currency'] ?? null,
            'currency_code'      => $currency['currency_code'] ?? null,
            'decimal_point'      => $currency['decimal_point'] ?? null,
            'variants'           => $variantPayload,
            'gst'                => $gstBreakdown,
        ];
    }

    private static function unlimitedFromStocks($storeStocks): int
    {
        return $storeStocks->where('is_listed', 1)->where('is_unlimited_stock', 1)->count() > 0 ? 1 : 0;
    }

    /**
     * Per-variant shape for the card payload: main image + attribute summary
     * ("Red / Large") + per-variant prices (tax-applied).
     */
    private static function shapeVariant(ProductVariant $v, float $taxPct, int $qty = 0, ?int $storeId = null): array
    {
        // Ensure store pricing is applied (idempotent; callers pre-apply for the card).
        // $storeId pins a specific store (cart line); null → cheapest listed (card "from").
        ProductHelper::applyStorePrice($v, $storeId);
        $priceWithTax = (float) $v->price * (1 + $taxPct / 100);
        // Slab-aware effective unit price for the requested quantity (qty 0 → 1 for
        // display). Slab match wins over discounted_price / base price.
        $qtyForPrice = $qty > 0 ? $qty : 1;
        $effUnit     = ProductHelper::slabUnitPrice($v, $qtyForPrice);
        $finalPrice  = $effUnit * (1 + $taxPct / 100);

        $variantAttributes = $v->attributeValues->map(fn ($av) => [
            'name'  => $av->attribute ? (string) $av->attribute->name : '',
            'value' => $av->attributeValue ? (string) $av->attributeValue->value : '',
        ])->filter(fn ($a) => $a['name'] !== '' || $a['value'] !== '')->values()->all();

        $attributesText = implode(' / ', array_values(array_filter(
            array_column($variantAttributes, 'value')
        )));

        $scopedStocks = $storeId !== null
            ? $v->storeStocks->where('store_id', $storeId)
            : $v->storeStocks;
        $listedStocks = $scopedStocks->where('is_listed', 1);
        $hasListedStore = $listedStocks->count() > 0;
        $hasInStockStore = $listedStocks->where('stock_status', 1)->count() > 0;
        $stock = (int) $listedStocks->where('stock_status', 1)->sum('available');
        $unlimited = self::unlimitedFromStocks($scopedStocks) === 1;
        $inStock = $hasListedStore && ($unlimited || ($hasInStockStore && $stock > 0));

        return [
            'id'                 => (int) $v->id,
            'name'               => (string) $v->name,
            'sku'                => $v->sku,
            'image'              => !empty($v->image) ? asset('storage/' . $v->image) : '',
            'attributes_text'    => $attributesText,
            'variant_attributes' => $variantAttributes,
            'price'              => (float) CommonHelper::doubleNumber($priceWithTax),
            'discounted_price'   => (float) CommonHelper::doubleNumber($finalPrice),
            'stock'              => $stock,
            'in_stock'           => $inStock,
            // 0 in product listing; the actual cart quantity in cart responses.
            'quantity'           => $qty,
        ];
    }

    /**
     * Task #6: Calculate per-item GST breakdown for cart items
     * Returns GST object with rate, cgst, sgst, igst, is_intra_state based on company/customer states
     */
    private static function calculateItemGST(Product $p, int $qty, float $itemTotal, ?string $companyState, ?string $customerState): array
    {
        $gstRate = (float) ($p->gst_rate ?? 0);
        $isGstInclusive = (bool) ($p->gst_inclusive ?? false);

        // Debug logging
        \Log::debug('[GST_CALC] calculateItemGST called:', [
            'product_id' => $p->id,
            'gst_rate' => $gstRate,
            'companyState' => $companyState,
            'customerState' => $customerState,
            'companyState_upper' => strtoupper($companyState ?? ''),
            'customerState_upper' => strtoupper($customerState ?? ''),
        ]);

        // Determine if intra-state or inter-state
        $isIntraState = ($companyState && $customerState && strtoupper($companyState) === strtoupper($customerState));
        
        \Log::debug('[GST_CALC] State comparison:', [
            'isIntraState' => $isIntraState,
            'comparison_result' => (strtoupper($companyState ?? '') === strtoupper($customerState ?? '')) ? 'MATCH' : 'MISMATCH',
        ]);

        if ($gstRate <= 0) {
            // 0% GST
            return [
                'rate' => 0,
                'cgst' => 0,
                'sgst' => 0,
                'igst' => 0,
                'gst_inclusive' => $isGstInclusive,
                'is_intra_state' => (bool) $isIntraState,
                'hsn_code' => $p->hsn_code,
                'base_amount' => round($itemTotal, 2),
            ];
        }

        if ($isGstInclusive) {
            // Price includes GST: extract GST from total
            $baseAmount = $itemTotal / (1 + $gstRate / 100);
        } else {
            // Price excludes GST: add GST to base
            $baseAmount = $itemTotal;
        }

        $gstAmount = round($baseAmount * $gstRate / 100, 2);

        if ($isIntraState) {
            // Intra-state: CGST + SGST (50-50 split)
            $cgst = round($gstAmount / 2, 2);
            $sgst = $gstAmount - $cgst;
            $igst = 0;
        } else {
            // Inter-state: IGST only
            $cgst = 0;
            $sgst = 0;
            $igst = $gstAmount;
        }

        return [
            'rate' => (float) $gstRate,
            'cgst' => (float) $cgst,
            'sgst' => (float) $sgst,
            'igst' => (float) $igst,
            'gst_inclusive' => $isGstInclusive,
            'is_intra_state' => (bool) $isIntraState,
            'hsn_code' => $p->hsn_code,
            'base_amount' => round($baseAmount, 2),
        ];
    }
}
