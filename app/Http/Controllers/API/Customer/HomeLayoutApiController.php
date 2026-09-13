<?php

namespace App\Http\Controllers\API\Customer;

use App\Helpers\CommonHelper;
use App\Helpers\CustomerProductShaper;
use App\Helpers\HomeLayoutResolver;
use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HomeLayoutApiController extends Controller
{
    /**
     * Resolves and returns the published Home Builder layout for the app/web,
     * populated with real products / categories / banners.
     *
     * Headers:
     *  - channel:     quick | ecommerce (optional, defaults to quick). Only
     *                 matters to disambiguate when a 'both' layout is matched;
     *                 channel-specific layouts ignore it.
     *  - Content-Language: language code for translatable text (optional)
     *
     * Query / form params:
     *  - latitude, longitude (required) — used to resolve the customer's zone.
     *  - category_id: optional, for category-wise layouts.
     *  - device:      app | web | tablet (default app).
     */
    public function getHomeLayout(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'latitude'  => 'required',
            'longitude' => 'required',
        ]);
        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        $lat  = (float) $request->latitude;
        $lng  = (float) $request->longitude;

        // Detect which channels this location is deliverable to. A point may sit in
        // a quick zone, an ecommerce zone, both, or neither (zones are per-channel).
        $quickZone = CommonHelper::getDeliverableCity($lat, $lng, 'quick');
        $ecomZone  = CommonHelper::getDeliverableCity($lat, $lng, 'ecommerce');

        $availableModes = [];
        if ($quickZone) {
            $availableModes[] = 'quick';
        }
        if ($ecomZone) {
            $availableModes[] = 'ecommerce';
        }
        if (empty($availableModes)) {
            return CommonHelper::responseError(__('not_deliverable_to_this_location'));
        }

        // Requested channel (header). Honor it when available here; otherwise fall
        // back to the location's available channel (quick preferred).
        $requested = strtolower(trim((string) $request->header('channel')));
        $channel = in_array($requested, $availableModes, true) ? $requested : $availableModes[0];
        $zone = $channel === 'quick' ? $quickZone : $ecomZone;

        // 'both' when the location supports quick + ecommerce, else the single channel.
        $availableMode = count($availableModes) === 2 ? 'both' : $availableModes[0];

        $zoneStores = Store::where('status', 1)
            ->where('zone_id', $zone->id)
            ->get();

        if ($zoneStores->isEmpty()) {
            return CommonHelper::responseError(__('not_deliverable_to_this_location'));
        }

        $storeIds = $zoneStores->pluck('id')->all();
        $storeClosed = 0;
        if ($channel === 'quick') {
            $openStores = $zoneStores->filter(fn($s) => CommonHelper::isStoreOpenNow($s));
            $storeClosed = ($zoneStores->isNotEmpty() && $openStores->isEmpty()) ? 1 : 0;
        }

        $categoryId = $request->input('category_id');
        $device     = in_array($request->input('device'), ['app', 'web', 'tablet'], true)
            ? $request->input('device') : 'app';

        $userId = $request->user('api-customers') ? $request->user('api-customers')->id : null;

        // One zone == one store, so store + delivery time are the same for every
        // product in the layout — resolve once. Delivery time is quick commerce only.
        $delivery      = CustomerProductShaper::zoneDelivery($zone, $lat, $lng);
        $storeName     = $delivery['store_name'];
        $timeToDeliver = $channel === 'quick' ? (int) $delivery['time_to_deliver'] : 0;

        $resolver = new HomeLayoutResolver();
        $result = $resolver->resolve(
            $channel,
            (int) $zone->id,
            $categoryId,
            $device,
            $storeIds,
            $userId,
            $storeName,
            $timeToDeliver,
            CommonHelper::countryCurrency($zone?->country)
        );

        // Button label(s): the served layout's label, plus per-channel labels for the
        // toggle (both channels' labels when the location supports both).
        $channelLabels = [];
        foreach ($availableModes as $m) {
            $z = $m === 'quick' ? $quickZone : $ecomZone;
            $channelLabels[$m] = $resolver->channelLabel($m, (int) $z->id);
        }

        // Random active-product names for the search bar's auto-scrolling placeholder.
        $searchSuggestions = CommonHelper::randomActiveProductNames(10);

        if (!$result) {
            return CommonHelper::responseSuccessWithData('success', [
                'layout'        => ['sections' => []],
                'home_type'     => 'single',
                'category_tabs' => [],
                'zone_id'       => (int) $zone->id,
                'zone_slug'     => $zone->slug,
                'available_modes' => $availableMode,
                'layout_mode'     => null,
                'quick_button_label'    => $channelLabels['quick'] ?? null,
                'ecommerce_button_label' => $channelLabels['ecommerce'] ?? null,
                'search_suggestions'     => $searchSuggestions,
                'store_closed'           => $storeClosed,
            ]);
        }

        $layoutMode = $result['layout_mode'] ?? null;
        unset($result['layout_mode']);

        $result['zone_id'] = (int) $zone->id;
        $result['zone_slug'] = $zone->slug;
        // available_modes: 'both' when the location supports quick + ecommerce (app
        // shows a toggle), else the single channel name.
        $result['available_modes'] = $availableMode;
        // layout_mode = which channel the served layout is for.
        $result['layout_mode'] = $layoutMode;
        // ecommerce_button_label = each channel's button name (null when not
        // available at this location).
        $result['quick_button_label']     = $channelLabels['quick'] ?? null;
        $result['ecommerce_button_label'] = $channelLabels['ecommerce'] ?? null;
        $result['search_suggestions']     = $searchSuggestions;
        // 1 = quick store closed now (products shown, but cart/order blocked).
        $result['store_closed']           = $storeClosed;

        if ($channel === 'quick') {
            $result['time_to_deliver'] = CustomerProductShaper::formatDeliveryTime($timeToDeliver, false);
            $result['distance']        = $delivery['distance'] . ' ' . $delivery['distance_unit'];
        }

        return CommonHelper::responseSuccessWithData('success', $result);
    }
}
