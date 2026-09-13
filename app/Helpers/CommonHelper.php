<?php

namespace App\Helpers;

use App\Events\OrderPlaced;
use App\Jobs\SendEmailJob;
use App\Models\Admin;
use App\Models\AdminToken;
use App\Models\Blog;
use App\Models\Brand;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Zone;
use App\Models\Country;
use App\Models\DeliveryCity;
use App\Models\DeliveryArea;
use App\Models\DeliveryBoy;
use App\Models\EmailTemplate;
use App\Models\EmailTemplateTranslation;
use App\Models\Favorite;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\OrderStatusList;
use App\Models\Product;
use App\Models\ProductImages;
use App\Models\ProductVariant;
use App\Models\PromoCode;
use App\Models\ReturnRequest;
use App\Models\ReturnStatusList;
use App\Services\LanguageService;
use App\Models\Store;
use App\Models\Setting;
use App\Models\WalletTransaction;
use App\Models\DeliveryBoySettlement;
use App\Models\DeliveryBoyCashCollection;
use App\Models\Transaction;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use App\Models\User;
use App\Models\UserAddress;
use App\Models\UserToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Response;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use App\Models\RatingImages;
use App\Models\ProductRating;
use Carbon\Carbon;
use App\Notifications\OrderNotification;
use App\Models\CartNotification;
use App\Models\CountryTranslation;
use App\Models\NotificationTemplate;
use App\Models\NotificationTemplateTranslation;
use Illuminate\Support\Facades\Config;
use App\Models\Language;
use App\Models\ReturnRequestStatus;
use App\Models\Role;
use App\Models\UserWallet;
use App\Models\WithdrawalRequest;
use App\Services\NotificationService;

class CommonHelper
{
    /** General mobile-number digit-count bounds every country must stay within. */
    public const MOBILE_LENGTH_MIN = 4;
    public const MOBILE_LENGTH_MAX = 15;

    public static function validateMobileForCountry($countryId, $mobile): ?string
    {
        $country = Country::find($countryId);
        if (!$country) {
            return __('country_not_found');
        }

        $digits = preg_replace('/\D+/', '', (string) $mobile);
        $len = strlen($digits);

        $min = (int) ($country->min_mobile_length ?: self::MOBILE_LENGTH_MIN);
        $max = (int) ($country->max_mobile_length ?: self::MOBILE_LENGTH_MAX);

        if ($len < $min || $len > $max) {
            // Replace :country BEFORE :count — ':count' is a substring of ':country'.
            return $min === $max
                ? str_replace([':country', ':count'], [$country->name, $min], __('mobile_must_be_exact_digits'))
                : str_replace([':country', ':min', ':max'], [$country->name, $min, $max], __('mobile_must_be_between_digits'));
        }

        return null;
    }

    public static function responseError($messageKey, $statusCode = null)
    {
        self::resolveResponseLanguage();
        $payload = [
            'status'  => 0,
            'message' => __($messageKey),
        ];
        if ($statusCode !== null) {
            $payload['status_code'] = $statusCode;
        }
        return Response::json($payload);
    }

    public static function responseErrorWithData($messageKey, $data, $statusCode = null)
    {
        self::resolveResponseLanguage();
        $payload = [
            'status'  => 0,
            'message' => __($messageKey),
            'data'    => $data
        ];
        if ($statusCode !== null) {
            $payload['status_code'] = $statusCode;
        }
        return Response::json($payload);
    }

    public static function responseSuccess($messageKey, $statusCode = null)
    {
        self::resolveResponseLanguage();
        $payload = [
            'status'  => 1,
            'message' => __($messageKey),
        ];
        if ($statusCode !== null) {
            $payload['status_code'] = $statusCode;
        }
        return Response::json($payload);
    }

    public static function responseWithData($data, $total = null, $statusCode = null)
    {
        self::resolveResponseLanguage();
        $payload = [
            'status'  => 1,
            'message' => __('success'),
            'data'    => $data,
        ];

        if ($total !== false) {
            $payload['total'] = $total ?? 1;
        }

        if ($statusCode !== null) {
            $payload['status_code'] = $statusCode;
        }
        return Response::json($payload);
    }

    public static function responseSuccessWithData($messageKey, $data, $statusCode = null)
    {
        self::resolveResponseLanguage();
        $payload = [
            'status'  => 1,
            'message' => __($messageKey),
            'data'    => $data,
        ];
        if ($statusCode !== null) {
            $payload['status_code'] = $statusCode;
        }
        return Response::json($payload);
    }

    public static function formatNumber(string $prefix, $id, int $pad = 5): string
    {
        return $prefix . str_pad((string) $id, $pad, '0', STR_PAD_LEFT);
    }

    /**
     * Resolve response language based on Content-Language header and available lang files.
     * - If header Content-Language matches a folder in resources/lang or a {code}.json file, use that.
     * - Otherwise fall back to current app locale.
     */
    protected static function resolveResponseLanguage(): void
    {
        // Default: current app locale
        $default = App::getLocale();

        if (!app()->has('request') || !request()) {
            App::setLocale($default);
            return;
        }

        $header = request()->header('Content-Language');
        if (!$header) {
            App::setLocale($default);
            return;
        }

        $code = trim(strtolower($header));
        if ($code === '') {
            App::setLocale($default);
            return;
        }

        $langDir  = resource_path('lang/' . $code);
        $langJson = resource_path('lang/' . $code . '.json');

        if (File::isDirectory($langDir) || File::exists($langJson)) {
            App::setLocale($code);
            return;
        }

        App::setLocale($default);
        return;
    }

    public static function getColumnComment($tableName, $columnName)
    {
        $databaseName = DB::connection()->getDatabaseName();
        $comments = DB::select("SELECT COLUMN_COMMENT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '$databaseName' AND TABLE_NAME = '$tableName' AND COLUMN_NAME = '$columnName'");
        return $comments[0]->COLUMN_COMMENT;
    }

    public static function slugify($text, $table = 'products', $field = 'slug')
    {
        // replace non letter or digits by -
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);

        $text = trim($text, '-');

        // remove duplicate -
        $text = preg_replace('~-+~', '-', $text);

        // lowercase
        $slug = strtolower($text);

        if (empty($slug)) {
            return 'n-a';
        }
        $total = DB::select(DB::raw("SELECT COUNT(id) AS total_slugs FROM $table WHERE $field  LIKE '$slug%'"));

        return ($total[0]->total_slugs > 0) ? ($slug . '-' . $total[0]->total_slugs) : $slug;
    }

    public static function convertSettingsInArray($settings): array
    {
        $imageArray = array("play_store_logo", "ios_store_logo", "favicon", "web_logo", "loading", "logo", "popup_image", "placeholder_image", "app_download_image");
        $data = array();
        foreach ($settings as $setting) {
            if (in_array($setting->variable, $imageArray)) {
                $data[$setting->variable] = self::getImage($setting->value);
            } else {
                $data[$setting->variable] = $setting->value;
            }
        }
        return $data;
    }

    public static function getSettings($variables)
    {
        $settings = Setting::whereIn('variable', $variables)->get();
        $settingsArray = self::convertSettingsInArray($settings);

        return $settingsArray;
    }

    public static function getBroadcastDriver(): string
    {
        $driver = config('broadcasting.default');

        return in_array($driver, ['reverb', 'pusher'], true) ? $driver : '';
    }

    /** toggle setting var => [surface slug, remark setting var]. */
    public const MAINTENANCE_SURFACES = [
        'website_mode'          => ['web', 'website_mode_remark'],
        'app_mode_customer'     => ['customer', 'app_mode_customer_remark'],
        'app_mode_delivery_boy' => ['delivery_boy', 'app_mode_delivery_boy_remark'],
    ];

    public static function applyMaintenance(string $toggleVar, int $mode): void
    {
        if (!isset(self::MAINTENANCE_SURFACES[$toggleVar])) {
            return;
        }
        [$surface, $remarkVar] = self::MAINTENANCE_SURFACES[$toggleVar];

        $setting = Setting::firstOrNew(['variable' => $toggleVar]);
        $setting->value = (string) ($mode ? 1 : 0);
        $setting->save();

        $raw = Setting::where('variable', $remarkVar)->value('value');
        $remark = [];
        if (!empty($raw)) {
            $decoded = json_decode($raw, true);
            $remark = is_array($decoded) ? $decoded : ['en' => (string) $raw];
        }

        try {
            event(new \App\Events\MaintenanceToggled($surface, (int) ($mode ? 1 : 0), $remark));
        } catch (\Throwable $e) {
            Log::error('MaintenanceToggled broadcast failed: ' . $e->getMessage());
        }
    }

    public static function getBroadcastClientConfig(): array
    {
        $driver = self::getBroadcastDriver();

        if ($driver === 'reverb') {
            return [
                'key'    => (string) config('broadcasting.connections.reverb.key'),
                'host'   => (string) config('broadcasting.connections.reverb.options.host'),
                'port'   => (int) config('broadcasting.connections.reverb.options.port'),
                'scheme' => (string) config('broadcasting.connections.reverb.options.scheme'),
            ];
        }

        if ($driver === 'pusher') {
            return [
                'key'     => (string) config('broadcasting.connections.pusher.key'),
                'cluster' => (string) config('broadcasting.connections.pusher.options.cluster'),
            ];
        }

        return [];
    }

    /**
     * A country's business timezone (countries.timezone) — used for wall-clock
     * evaluations: store operating hours, zone surge slots, promo scheduling.
     * Falls back to UTC when unset/unknown. Cached per request.
     */
    public static function countryTimezone($countryId): string
    {
        static $cache = [];
        $countryId = (int) $countryId;
        if (!$countryId) {
            return 'UTC';
        }
        if (!array_key_exists($countryId, $cache)) {
            $tz = Country::where('id', $countryId)->value('timezone');
            $cache[$countryId] = !empty($tz) ? $tz : 'UTC';
        }
        return $cache[$countryId];
    }

    /** Business timezone for a zone (via its country). */
    public static function zoneTimezone($zone): string
    {
        return self::countryTimezone(is_object($zone) ? ($zone->country_id ?? null) : null);
    }

    /** Business timezone for a store (via its zone's country). Cached per request. */
    public static function storeTimezone($store): string
    {
        static $zoneCountry = [];
        $zoneId = (int) ($store->zone_id ?? 0);
        if (!$zoneId) {
            return 'UTC';
        }
        if (!array_key_exists($zoneId, $zoneCountry)) {
            $zoneCountry[$zoneId] = (int) Zone::where('id', $zoneId)->value('country_id');
        }
        return self::countryTimezone($zoneCountry[$zoneId]);
    }

    /*
     * Dates/times are never formatted server-side: APIs emit raw UTC values and
     * every client (apps + admin panel) converts to the device's local timezone
     * and applies the selected country's date_format / time_format itself.
     */

    public static function getDeliveryBoyBonusSettings(): array
    {
        $variablesArray = array("delivery_boy_bonus_settings", "delivery_boy_bonus_type", "delivery_boy_bonus_percentage", "delivery_boy_bonus_min_amount", "delivery_boy_bonus_max_amount");
        $bonus =  self::getSettings($variablesArray);
        $bonus['delivery_boy_bonus_settings'] = intval($bonus['delivery_boy_bonus_settings']);
        $bonus['delivery_boy_bonus_type'] = intval($bonus['delivery_boy_bonus_type']);
        $bonus['delivery_boy_bonus_percentage'] = floatval($bonus['delivery_boy_bonus_percentage']);
        $bonus['delivery_boy_bonus_min_amount'] = floatval($bonus['delivery_boy_bonus_min_amount']);
        $bonus['delivery_boy_bonus_max_amount'] = floatval($bonus['delivery_boy_bonus_max_amount']);
        return $bonus;
    }

    public static function getMainCategories($request)
    {
        $query = Category::orderBy('id', 'DESC')
            ->where(['parent_id' => 0, 'status' => 1]);

        if (isset($request->search) && !empty($request->search)) {
            $searchTerm = $request->search;
            $query->where('name', 'LIKE', '%' . $searchTerm . '%');
        }

        $useContentLanguage = $request
            && $request->header('Content-Language') !== null
            && trim((string) $request->header('Content-Language')) !== '';

        if ($useContentLanguage) {
            $langCode = app()->has('lang_code') ? app('lang_code') : 'en';
            app()->setLocale($langCode);
        }

        $query->with('translations');

        $categories = $query->get();

        if ($useContentLanguage && $categories->isNotEmpty()) {
            $langId = app(LanguageService::class)->getCurrentId();

            // Read translatable fields from the model, same pattern as CountryApiController
            $translatableFields = (function () {
                return $this->getTranslatableAttributes();
            })->call($categories->first() ?? new Category());

            $categories = $categories->map(function ($category) use ($langId, $translatableFields) {
                $translation = $category->getRelation('translations')
                    ->where('language_id', $langId)
                    ->first();

                $transData = ['language_id' => $langId];
                foreach ($translatableFields as $field) {
                    // If translation value exists, use it; otherwise use base-table value
                    $transData[$field] = ($translation && isset($translation->$field) && $translation->$field !== '')
                        ? $translation->$field
                        : ($category->getAttributeValue($field) ?? '');
                }

                $categoryArray = $category->toArray();
                $categoryArray['translations'] = $transData;

                // Hide internal flags and nested active children completely on parent
                unset(
                    $categoryArray['has_child'],
                    $categoryArray['has_active_child'],
                    $categoryArray['cat_active_childs']
                );

                return $categoryArray;
            });
        } else {
            // When not using Content-Language, keep original model collection but hide internals
            $categories = $categories->makeHidden(['has_child', 'has_active_child', 'cat_active_childs']);
        }

        return $categories;
    }

    public static function uploadFile($source, ?string $field, string $directory, ?string $oldPath = null, string $disk = 'public'): ?string
    {
        $file = $source instanceof \Illuminate\Http\UploadedFile
            ? $source
            : (($field !== null && $source->hasFile($field)) ? $source->file($field) : null);
        if (is_array($file)) {
            $file = $file[0] ?? null;
        }
        if (!$file || !$file->isValid()) {
            return $oldPath;
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');

        $base = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $base = preg_replace('/[^A-Za-z0-9_-]+/', '-', $base);
        $base = trim($base, '-_');
        if ($base === '') {
            $base = 'file';
        }

        $fileName = $base . '.' . $extension;
        $counter = 1;
        while (Storage::disk($disk)->exists($directory . '/' . $fileName)) {
            $fileName = $base . '_' . $counter . '.' . $extension;
            $counter++;
        }

        $path = Storage::disk($disk)->putFileAs($directory, $file, $fileName);

        if ($path && $oldPath && $oldPath !== $path) {
            self::deleteFile($oldPath, $disk);
        }

        return $path ?: $oldPath;
    }

    /**
     * Enhanced upload that supports both local storage and Cloudinary.
     * If Cloudinary is configured and $useCloudinary is true, uploads to Cloudinary.
     * Otherwise falls back to local storage via uploadFile().
     */
    public static function uploadFileWithCloudinary(
        $source,
        ?string $field,
        string $directory,
        ?string $oldPath = null,
        string $disk = 'public',
        bool $useCloudinary = true
    ): ?string {
        $file = $source instanceof \Illuminate\Http\UploadedFile
            ? $source
            : (($field !== null && $source->hasFile($field)) ? $source->file($field) : null);
        if (is_array($file)) {
            $file = $file[0] ?? null;
        }
        if (!$file || !$file->isValid()) {
            return $oldPath;
        }

        // Try Cloudinary if enabled and configured
        if ($useCloudinary && \App\Helpers\CloudinaryHelper::isConfigured()) {
            try {
                $cloudinaryUrl = \App\Helpers\CloudinaryHelper::uploadImage($file, $directory);
                // Delete old file from Cloudinary if it exists
                if (!empty($oldPath) && preg_match('~^https?://~', $oldPath)) {
                    try {
                        if (preg_match('/\/upload\/(?:v\d+\/)?(.+?)(?:\.\w+)?$/', $oldPath, $matches)) {
                            \App\Helpers\CloudinaryHelper::delete($matches[1]);
                        }
                    } catch (\Exception $e) {
                        Log::warning('[uploadFileWithCloudinary] Failed to delete old Cloudinary file: ' . $e->getMessage());
                    }
                }
                return $cloudinaryUrl;
            } catch (\Exception $e) {
                Log::warning('[uploadFileWithCloudinary] Cloudinary upload failed, falling back to local storage: ' . $e->getMessage());
                // Fall through to local storage
            }
        }

        // Fall back to local storage
        return self::uploadFile($source, $field, $directory, $oldPath, $disk);
    }

    /** Delete a stored file if it exists; safe on null/empty paths. */
    public static function deleteFile(?string $path, string $disk = 'public'): void
    {
        try {
            if ($path && Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }
        } catch (\Exception $e) {
            Log::warning('[deleteFile] failed for ' . $path . ': ' . $e->getMessage());
        }
    }

    public static function validatePromoCode($user_id, $promo_code, $total, $context = [])
    {
        // $unlock = optional "add X more to unlock" nudge; '' for failures the user can't fix by adding to cart.
        $fail = function ($message, $unlock = '') {
            return ['is_applicable' => 0, 'message' => $message, 'unlock_message' => $unlock];
        };

        $code = PromoCode::where('promo_code', $promo_code)->first();
        if (empty($code)) {
            return $fail(__('promo_code_not_available'));
        }
        if ((int) $code->status === 0) {
            return $fail(__('this_promo_code_is_expired_or_invalid'));
        }

        $user = auth()->user();
        if (empty($user)) {
            return $fail(__('invalid_user_data'));
        }

        // Scheduling (dates, time windows, weekdays) is evaluated in the customer's
        // country timezone, resolved from the delivery location's zone. No location
        // (or no matching zone) falls back to UTC.
        $contextZone = null;
        if (!empty($context['latitude']) && !empty($context['longitude'])) {
            $contextZone = self::getDeliverableCity($context['latitude'], $context['longitude'], $context['channel'] ?? null);
        }
        $nowTz = \Carbon\Carbon::now(self::zoneTimezone($contextZone));

        // ---- 6. Scheduling: permanent / date range ----
        if ((int) $code->is_permanent !== 1) {
            $today = $nowTz->format('Y-m-d');
            if (!empty($code->start_date) && $today < date('Y-m-d', strtotime($code->start_date))) {
                return $fail(__('this_promo_code_cant_be_used_before') . ' ' . date('d-m-Y', strtotime($code->start_date)));
            }
            if (!empty($code->end_date) && $today > date('Y-m-d', strtotime($code->end_date))) {
                return $fail(__('this_promo_code_cant_be_used_after') . ' ' . date('d-m-Y', strtotime($code->end_date)));
            }
        }

        // ---- 6. Time-of-day window ----
        if ((int) $code->full_day_promotion !== 1 && !empty($code->start_time) && !empty($code->end_time)) {
            $nowT = $nowTz->format('H:i:s');
            if ($nowT < date('H:i:s', strtotime($code->start_time)) || $nowT > date('H:i:s', strtotime($code->end_time))) {
                return $fail(__('this_promo_code_is_not_active_at_this_time'));
            }
        }

        // ---- 6. Weekday recurrence ----
        $weekdays = is_array($code->weekday_recurrence) ? $code->weekday_recurrence : [];
        if (!empty($weekdays) && !in_array((int) $nowTz->dayOfWeek, array_map('intval', $weekdays), true)) {
            return $fail(__('this_promo_code_is_not_active_today'));
        }

        // Cart is needed for applicability + the min-quantity nudge below.
        // Scope to the request channel (quick/ecommerce); channel-less legacy rows match either.
        $channel = isset($context['channel']) && in_array($context['channel'], ['quick', 'ecommerce'], true)
            ? $context['channel'] : null;
        // Price is per-store (PVSS): join the cart line's fulfilling store for its price.
        $cart = Cart::join('products', 'carts.product_id', '=', 'products.id')
            ->join('product_variants', 'carts.product_variant_id', '=', 'product_variants.id')
            ->leftJoin('product_variant_store_stocks as pvss', function ($j) {
                $j->on('pvss.product_variant_id', '=', 'carts.product_variant_id')
                    ->on('pvss.store_id', '=', 'carts.store_id');
            })
            ->leftJoin('taxes', 'products.tax_id', '=', 'taxes.id')
            ->where('carts.user_id', $user_id)
            ->when($channel !== null, function ($q) use ($channel) {
                $q->where(function ($w) use ($channel) {
                    $w->where('carts.channel', $channel)->orWhereNull('carts.channel');
                });
            })
            ->get([
                'carts.product_id',
                'carts.store_id',
                'carts.qty',
                'products.category_id',
                'products.brand_id',
                'pvss.price',
                'pvss.discounted_price',
                'taxes.percentage as tax_percentage',
            ]);

        // 3. Applicability + eligible base.
        // For 'all' the discount applies to the whole order amount ($total). For a
        // specific applicability (categories / products / brands) it applies ONLY to the
        // matching cart lines' price (tax-applied final price * qty).
        $appIds = is_array($code->applicability_ids) ? array_map('intval', $code->applicability_ids) : [];
        $eligibleItems = null;
        switch ($code->applicability) {
            case 'categories':
                $eligibleItems = $cart->whereIn('category_id', $appIds);
                break;
            case 'products':
                $eligibleItems = $cart->whereIn('product_id', $appIds);
                break;
            case 'brands':
                $eligibleItems = $cart->whereIn('brand_id', $appIds);
                break;
        }
        if ($eligibleItems !== null && (empty($appIds) || $eligibleItems->isEmpty())) {
            return $fail(__('this_promo_code_is_not_applicable_to_items_in_your_cart'));
        }

        // Discount base: whole order for 'all', else the matching items' subtotal.
        $discount_base = (float) $total;
        if ($eligibleItems !== null) {
            $discount_base = 0;
            foreach ($eligibleItems as $line) {
                $unit = ((float) $line->discounted_price > 0) ? (float) $line->discounted_price : (float) $line->price;
                $unit = $unit * (1 + ((float) $line->tax_percentage) / 100);
                $discount_base += $unit * (int) $line->qty;
            }
        }

        // ---- 10. Country restriction (zone already resolved above for scheduling) ----
        $countryIds = is_array($code->country_ids) ? array_map('intval', $code->country_ids) : [];
        if (!empty($countryIds)) {
            if (empty($context['latitude']) || empty($context['longitude'])) {
                return $fail(__('location_required_for_this_promo_code'));
            }
            $userCountryId = $contextZone ? (int) ($contextZone->country_id ?? 0) : 0;
            if (!in_array($userCountryId, $countryIds, true)) {
                return $fail(__('this_promo_code_is_not_available_in_your_country'));
            }
        }

        // ---- 10. Zone restriction (1 zone = 1 store, so zone covers store scope) ----
        $zoneIds = is_array($code->zone_ids) ? array_map('intval', $code->zone_ids) : [];
        if (!empty($zoneIds)) {
            if (empty($context['latitude']) || empty($context['longitude'])) {
                return $fail(__('location_required_for_this_promo_code'));
            }
            $userZoneId = $contextZone ? (int) $contextZone->id : 0;
            if (!in_array($userZoneId, $zoneIds, true)) {
                return $fail(__('this_promo_code_is_not_available_in_your_area'));
            }
        }

        // ---- 5 / 7. New-user restriction (audience 'new' = no prior orders) ----
        if ($code->audience_type === 'new') {
            $userOrderCount = Order::where('user_id', $user_id)->count();
            if ($userOrderCount > 0) {
                return $fail(__('this_promo_code_is_only_for_new_users'));
            }
        }

        // ---- 7. Specific-user audience ----
        if ($code->audience_type === 'specific') {
            $audienceIds = is_array($code->audience_ids) ? array_map('intval', $code->audience_ids) : [];
            if (!in_array((int) $user_id, $audienceIds, true)) {
                return $fail(__('this_promo_code_is_not_available_for_your_account'));
            }
        }

        // ---- 5. Usage limits ----
        if ((int) $code->total_usage_limit > 0) {
            $totalUsed = Order::where('promo_code_id', $code->id)->count();
            if ($totalUsed >= (int) $code->total_usage_limit) {
                return $fail(__('this_promo_code_usage_limit_has_been_reached'));
            }
        }
        if ((int) $code->per_user_usage_limit > 0) {
            $userUsed = Order::where('user_id', $user_id)->where('promo_code_id', $code->id)->count();
            if ($userUsed >= (int) $code->per_user_usage_limit) {
                return $fail(__('you_have_already_used_this_promo_code_the_maximum_number_of_times'));
            }
        }

        // ---- 10. Sales channel restriction (quick / ecommerce / both) ----
        $channel = $context['channel'] ?? null;
        if ($code->channel !== 'both' && $channel !== null && $channel !== $code->channel) {
            return $fail(__('this_promo_code_is_not_available_for_this_channel'));
        }

        // ---- 9. Platform restriction ----
        $platform = $context['platform'] ?? null;
        if ($code->platform !== 'all' && $platform !== null && $platform !== $code->platform) {
            return $fail(__('this_promo_code_is_not_available_on_this_platform'));
        }

        // ---- 4. Cart thresholds (LAST): only an "add more to unlock" nudge is set here,
        //         so a nudge means every hard rule (audience, schedule, channel, ...) passed. ----
        if ($total < (float) $code->minimum_order_amount) {
            $shortfall = (float) $code->minimum_order_amount - (float) $total;
            $unlock = __('add') . ' ' . round($shortfall, 2) . ' ' . __('more_to_unlock_this_offer');
            return $fail(__('this_promo_code_is_applicable_only_for_order_amount_greater_than_or_equal_to') . ' ' . $code->minimum_order_amount, $unlock);
        }
        if ((int) $code->min_product_quantity > 0) {
            $cartQty = (int) $cart->sum('qty');
            if ($cartQty < (int) $code->min_product_quantity) {
                $needQty = (int) $code->min_product_quantity - $cartQty;
                $unlock = __('add') . ' ' . $needQty . ' ' . __('more_items_to_unlock_this_offer');
                return $fail(__('this_promo_code_requires_at_least') . ' ' . $code->min_product_quantity . ' ' . __('items_in_cart'), $unlock);
            }
        }

        // ---- 2. Discount computation ----
        $free_delivery = 0;
        if ($code->discount_type === 'percentage') {
            // Percentage applies to the discount base (eligible items for a specific
            // applicability, whole order amount for 'all').
            $discount = $discount_base / 100 * (float) $code->discount;
            if ((float) $code->max_discount_amount > 0 && $discount > (float) $code->max_discount_amount) {
                $discount = (float) $code->max_discount_amount;
            }
        } elseif ($code->discount_type === 'free_delivery') {
            $discount = 0;          // delivery is zeroed at cart/checkout
            $free_delivery = 1;
        } else { // flat
            $discount = (float) $code->discount;
            // A flat discount can't exceed the eligible base it applies to.
            if ($discount > $discount_base) {
                $discount = $discount_base;
            }
        }
        $discount = max(0, $discount);
        // Wallet cashback is credited to the wallet later — it does NOT reduce what the
        // user pays now. Only 'instant' discounts come off the payable total.
        $instant_deduction = ($code->discount_apply_type === 'wallet') ? 0 : $discount;
        $discounted_amount = max(0, (float) $total - $instant_deduction);

        $decimals = (int) (Setting::get_value('decimal_point') ?? 2);
        if ($decimals < 0) {
            $decimals = 2;
        }

        return [
            'promo_code_id'        => $code->id,
            'is_applicable'        => 1,
            'message'              => __('promo_code_applied_successfully'),
            'unlock_message'       => '',
            'promo_code'           => $promo_code,
            'title'                => $code->title,
            'description'          => $code->description,
            'image_url'            => $code->image_url,
            'promo_code_message'   => $code->description,
            'total'                => round((float) $total, $decimals),
            'discount'             => round((float) $discount, $decimals),
            'discounted_amount'    => round((float) $discounted_amount, $decimals),
            'discount_type'        => $code->discount_type,
            'discount_apply_type'  => $code->discount_apply_type,
            'max_discount_amount'  => (float) $code->max_discount_amount,
            'minimum_order_amount' => (float) $code->minimum_order_amount,
            'min_product_quantity' => (int) $code->min_product_quantity,
            'free_delivery'        => $free_delivery,
        ];
    }

    public static function getValidatedPromoCode($promocode_id, $total, $user_id, $context = [])
    {
        $code = PromoCode::find($promocode_id);
        if (empty($code)) {
            return ['is_applicable' => 0, 'message' => __('promo_code_not_available')];
        }

        return self::validatePromoCode($user_id, $code->promo_code, $total, $context);
    }

    /**
     * Find the single best "add a little more to unlock this coupon" nudge for a cart.
     * Considers only public, in-date coupons the user passes every check on EXCEPT the
     * cart-amount / item-quantity threshold. Picks the one needing the least to unlock.
     * Returns ['unlock_message','unlock_promo_code','unlock_promo_code_id'] — all empty when none.
     */
    public static function getCartPromoNudge($user_id, $sub_total, $cart_qty, $context = [])
    {
        $empty = ['unlock_message' => '', 'unlock_promo_code' => '', 'unlock_promo_code_id' => 0];

        $coupons = PromoCode::where('status', 1)
            ->where('visibility', 'public')
            ->where(function ($q) {
                $q->where('is_permanent', 1)
                    ->orWhereRaw('CURDATE() between start_date and end_date');
            })
            ->get();
        if ($coupons->isEmpty()) {
            return $empty;
        }

        $best = null;
        $bestEffort = null;
        foreach ($coupons as $c) {
            $res = self::validatePromoCode($user_id, $c->promo_code, $sub_total, $context);
            // Already usable, or blocked by something the user can't fix by adding to cart.
            if ((int) ($res['is_applicable'] ?? 0) === 1 || empty($res['unlock_message'])) {
                continue;
            }
            $amtShort = max(0, (float) $c->minimum_order_amount - (float) $sub_total);
            $qtyShort = max(0, (int) $c->min_product_quantity - (int) $cart_qty);
            // Rank amount-nudges by money needed; qty-only nudges come after, by items needed.
            $effort = $amtShort > 0 ? $amtShort : (1e9 + $qtyShort);
            if ($bestEffort === null || $effort < $bestEffort) {
                $bestEffort = $effort;
                $best = [
                    'unlock_message'       => $res['unlock_message'],
                    'unlock_promo_code'    => $c->promo_code,
                    'unlock_promo_code_id' => $c->id,
                ];
            }
        }

        return $best ?? $empty;
    }

    /**
     * Resolve the zone an order belongs to (from its snapshotted lat/long + channel).
     * Used by payment flows so gateway credentials come from the order's zone.
     */
    public static function resolveOrderZone($order)
    {
        if (!$order) {
            return null;
        }
        // Prefer the snapshotted zone_id; fall back to geofence from the order address.
        if (!empty($order->zone_id)) {
            return Zone::find($order->zone_id);
        }
        $addr = self::addressObject($order->address);
        return self::getDeliverableCity($addr['latitude'] ?? null, $addr['longitude'] ?? null, $order->channel ?? null);
    }

    /**
     * A country's payment gateway config map (country is the source of truth now).
     */
    public static function countryPaymentGateways($country): array
    {
        return is_array($country?->payment_gateways ?? null) ? $country->payment_gateways : [];
    }

    public static function isCodAllowed($country, array $items = []): int
    {
        $gateways = self::countryPaymentGateways($country);

        // First: Check if COD is enabled at country level
        if (($gateways['cod_payment_method'] ?? 0) != 1) {
            return 0;
        }

        // Second: ALWAYS check product-level cod_allowed field
        // If any item has cod_allowed != 1, reject COD
        if (!empty($items)) {
            foreach ($items as $item) {
                $flag = is_array($item) ? ($item['cod_allowed'] ?? 0) : ($item->cod_allowed ?? 0);
                if ((int) $flag !== 1) {
                    return 0;  // Product doesn't allow COD
                }
            }
            // All items allow COD
            return 1;
        }

        // No items provided - check mode
        if (($gateways['cod_mode'] ?? null) == Setting::$codModeGlobal) {
            return 1;
        }

        return 0;
    }

    private static $defaultCountry = false;

    public static function validatePasswordPolicy(?string $password): ?string
    {
        $password = (string) $password;
        $len = mb_strlen($password);

        $min = (int) (Setting::get_value('password_min_length') ?: 0);
        if ($min <= 0) {
            $min = 5; // sensible default when unset
        }
        if ($len < $min) {
            return __('password_must_be_at_least_x_characters', ['count' => $min]);
        }

        $max = (int) (Setting::get_value('password_max_length') ?: 0);
        if ($max > 0 && $len > $max) {
            return __('password_must_not_exceed_x_characters', ['count' => $max]);
        }

        $on = fn ($key) => in_array((string) Setting::get_value($key), ['1', 'true', 'on'], true);

        if ($on('password_require_uppercase') && !preg_match('/[A-Z]/', $password)) {
            return __('password_must_contain_an_uppercase_letter');
        }
        if ($on('password_require_lowercase') && !preg_match('/[a-z]/', $password)) {
            return __('password_must_contain_a_lowercase_letter');
        }
        if ($on('password_require_number') && !preg_match('/[0-9]/', $password)) {
            return __('password_must_contain_a_number');
        }
        if ($on('password_require_special') && !preg_match('/[^A-Za-z0-9]/', $password)) {
            return __('password_must_contain_a_special_character');
        }

        return null;
    }

    public static function defaultCountry()
    {
        if (self::$defaultCountry === false) {
            self::$defaultCountry = Country::where('is_default', 1)->first()
                ?? Country::where('status', 1)->orderBy('id')->first();
        }
        return self::$defaultCountry;
    }

    public static function categoryIdsWithProducts(?array $storeIds = null, ?string $channel = null): array
    {
        $query = Product::query()->where('status', 1)->where('is_draft', 0);

        if ($channel !== null && in_array($channel, ['quick', 'ecommerce'], true)) {
            $query->whereIn('sales_channel', [$channel, 'both']);
        }

        $query->whereExists(function ($sub) use ($storeIds) {
            $sub->select(DB::raw(1))
                ->from('product_variants as pv')
                ->join('product_variant_store_stocks as pvss', 'pvss.product_variant_id', '=', 'pv.id')
                ->whereColumn('pv.product_id', 'products.id')
                ->where('pvss.is_listed', 1);
            if ($storeIds !== null) {
                $sub->whereIn('pvss.store_id', $storeIds ?: [0]);
            }
        });

        $direct = $query->distinct()->pluck('category_id')->filter()->map('intval')->all();
        if (empty($direct)) {
            return [];
        }

        $parentOf = Category::where('status', 1)->pluck('parent_id', 'id')
            ->map(fn ($v) => (int) $v)->all();

        $keep = [];
        foreach ($direct as $cid) {
            $cur = $cid;
            $guard = 0;
       
            while ($cur && isset($parentOf[$cur]) && !isset($keep[$cur]) && $guard++ < 50) {
                $keep[$cur] = true;
                $cur = $parentOf[$cur];
            }
        }

        return array_map('intval', array_keys($keep));
    }

    public static function countryCurrency($country): array
    {
        $country = $country ?: self::defaultCountry();

        return [
            'currency'      => $country?->currency ?? null,
            'currency_code' => $country?->currency_code ?? null,
            'decimal_point' => $country ? (int) ($country->decimal_point ?? 2) : 2,
        ];
    }

    /** Resolve the country covering a lat/long (via the zone geofence). */
    public static function resolveCountry($latitude, $longitude, $channel = null)
    {
        return self::getDeliverableCity($latitude, $longitude, $channel)?->country;
    }

    /** The country an order belongs to (from its stored snapshot country_id). */
    public static function resolveOrderCountry($order)
    {
        if (!$order || empty($order->country_id)) {
            return null;
        }
        return Country::find($order->country_id);
    }

    public static function getDeliverableCity($latitude, $longitude, $channel = null)
    {
        $point = ['lat' => $latitude, 'lng' => $longitude];

        $zones = Zone::where('status', 1)->serving($channel)->get();

        \Log::debug('[GET_DELIVERABLE_CITY]', [
            'lat' => $latitude,
            'lng' => $longitude,
            'channel' => $channel,
            'zones_found' => $zones->count(),
        ]);

        foreach ($zones as $zone) {
            $polygon = $zone->polygonFor($channel);
            
            $hasPolygon = is_array($polygon) && !empty($polygon);
            \Log::debug('[ZONE_CHECK]', [
                'zone_id' => $zone->id,
                'zone_name' => $zone->name,
                'has_polygon' => $hasPolygon,
                'sales_channel' => $zone->sales_channel,
            ]);
            
            if ($hasPolygon && self::isPointInPolygon($point, $polygon)) {
                \Log::debug('[ZONE_MATCHED]', [
                    'zone_id' => $zone->id,
                    'zone_name' => $zone->name,
                ]);
                return $zone;
            }
        }

        \Log::warning('[NO_ZONE_MATCHED]', [
            'lat' => $latitude,
            'lng' => $longitude,
            'channel' => $channel,
        ]);
        return null;
    }

    public static function getSellerIds($latitude, $longitude)
    {
        $point = ['lat' => $latitude, 'lng' => $longitude];
        $zones = Zone::where('status', 1)->get();
        $matchedZoneIds = [];

        foreach ($zones as $zone) {
            foreach ([$zone->polygon_boundary_quick, $zone->polygon_boundary_ecommerce] as $polygon) {
                if (is_array($polygon) && !empty($polygon) && self::isPointInPolygon($point, $polygon)) {
                    $matchedZoneIds[] = $zone->id;
                    break;
                }
            }
        }

        return self::getSellerIdsfromCityIds($matchedZoneIds);
    }
    public static function isPointInPolygon($point, $polygon)
    {
        if (empty($polygon) || !is_array($polygon)) {
            return false; // Return false if polygon data is not valid
        }

        $x = $point['lng'];
        $y = $point['lat'];

        $vertices = $polygon;
        $count = count($vertices);

        if ($count < 3) {
            return false; // A polygon must have at least 3 vertices
        }

        $inside = false;
        $p1x = $vertices[0]['lng'];
        $p1y = $vertices[0]['lat'];

        for ($i = 1; $i <= $count; $i++) {
            $p2x = $vertices[$i % $count]['lng'];
            $p2y = $vertices[$i % $count]['lat'];

            if ($y > min($p1y, $p2y)) {
                if ($y <= max($p1y, $p2y)) {
                    if ($x <= max($p1x, $p2x)) {
                        if ($p1y != $p2y) {
                            $xinters = ($y - $p1y) * ($p2x - $p1x) / ($p2y - $p1y) + $p1x;
                        }
                        if ($p1x == $p2x || $x <= $xinters) {
                            $inside = !$inside;
                        }
                    }
                }
            }

            $p1x = $p2x;
            $p1y = $p2y;
        }

        return $inside;
    }

    public static function segmentsIntersect($a, $b, $c, $d)
    {
        $ax = $a['lng'];
        $ay = $a['lat'];
        $bx = $b['lng'];
        $by = $b['lat'];
        $cx = $c['lng'];
        $cy = $c['lat'];
        $dx = $d['lng'];
        $dy = $d['lat'];

        $d1 = ($cx - $ax) * ($by - $ay) - ($cy - $ay) * ($bx - $ax);
        $d2 = ($dx - $ax) * ($by - $ay) - ($dy - $ay) * ($bx - $ax);
        $d3 = ($ax - $cx) * ($dy - $cy) - ($ay - $cy) * ($dx - $cx);
        $d4 = ($bx - $cx) * ($dy - $cy) - ($by - $cy) * ($dx - $cx);

        return (($d1 > 0 && $d2 < 0) || ($d1 < 0 && $d2 > 0))
            && (($d3 > 0 && $d4 < 0) || ($d3 < 0 && $d4 > 0));
    }

    public static function polygonsOverlap($a, $b)
    {
        if (!is_array($a) || !is_array($b) || count($a) < 3 || count($b) < 3) {
            return false;
        }
        foreach ($a as $p) {
            if (self::isPointInPolygon($p, $b)) {
                return true;
            }
        }
        foreach ($b as $p) {
            if (self::isPointInPolygon($p, $a)) {
                return true;
            }
        }
        $ca = count($a);
        $cb = count($b);
        for ($i = 0; $i < $ca; $i++) {
            $a1 = $a[$i];
            $a2 = $a[($i + 1) % $ca];
            for ($j = 0; $j < $cb; $j++) {
                $b1 = $b[$j];
                $b2 = $b[($j + 1) % $cb];
                if (self::segmentsIntersect($a1, $a2, $b1, $b2)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Straight-line (haversine) distance between two lat/long points, in km.
     */
    public static function straightLineDistanceKm($latFrom, $lngFrom, $latTo, $lngTo)
    {
        if ($latFrom === null || $lngFrom === null || $latTo === null || $lngTo === null) {
            return null;
        }
        $earthRadius = 6371; // km
        $latF = deg2rad((float) $latFrom);
        $lngF = deg2rad((float) $lngFrom);
        $latT = deg2rad((float) $latTo);
        $lngT = deg2rad((float) $lngTo);

        $latDelta = $latT - $latF;
        $lngDelta = $lngT - $lngF;

        $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
            cos($latF) * cos($latT) * pow(sin($lngDelta / 2), 2)));

        return $angle * $earthRadius;
    }

    /**
     * Is a store open right now per its day-wise operating_hours?
     * Shape per day: ['open'=>'HH:MM','close'=>'HH:MM','break_time'=>'13:00-14:00','closed'=>bool].
     * Rules: `closed` day => closed; outside open–close window => closed; inside the
     * break window => closed (store paused). No schedule / day unset => treated open.
     * Times are wall-clock in the store's country timezone (via its zone).
     */
    public static function isStoreOpenNow($store, $now = null): bool
    {
        $hours = is_array($store->operating_hours) ? $store->operating_hours : [];
        if (empty($hours)) {
            return true;
        }
        // operating_hours are wall-clock times in the store's country timezone.
        $now = ($now ?: now())->copy()->setTimezone(self::storeTimezone($store));
        $dayKey = strtolower($now->format('l')); // monday, tuesday, ...
        $today  = $hours[$dayKey] ?? null;
        if (!$today) {
            return true;
        }
        if (!empty($today['closed'])) {
            return false;
        }

        $cur   = (int) $now->format('H') * 60 + (int) $now->format('i');
        $open  = self::minutesOfDay($today['open'] ?? '');
        $close = self::minutesOfDay($today['close'] ?? '');
        if ($open !== null && $close !== null) {
            $isOpen = $close > $open
                ? ($cur >= $open && $cur < $close)   // same-day window
                : ($cur >= $open || $cur < $close);  // overnight window
            if (!$isOpen) {
                return false;
            }
        }

        // Break window — store paused, treated as closed.
        $break = (string) ($today['break_time'] ?? '');
        if (strpos($break, '-') !== false) {
            [$bs, $be] = array_map('trim', explode('-', $break, 2));
            $bStart = self::minutesOfDay($bs);
            $bEnd   = self::minutesOfDay($be);
            if ($bStart !== null && $bEnd !== null && $bEnd > $bStart && $cur >= $bStart && $cur < $bEnd) {
                return false;
            }
        }
        return true;
    }

    private static function minutesOfDay($time): ?int
    {
        $time = trim((string) $time);
        if ($time === '' || strpos($time, ':') === false) {
            return null;
        }
        [$h, $m] = array_map('intval', explode(':', $time));
        return $h * 60 + $m;
    }

    public static function getSellerIdsfromCityIds(array $cityIds)
    {
        if (empty($cityIds)) {
            return [];
        }

        // Stores carry one zone per channel; no channel context here, so match either slot.
        $ids = array_map('intval', $cityIds);
        return Store::where('status', 1)
            ->where(function ($q) use ($ids) {
                $q->whereIn('zone_id', $ids);
            })
            ->pluck('id');
    }
    public static function getProductByVariantId($arr)
    {
        if (!empty($arr)) {
            $variants = ProductVariant::select(
                "pv.*",
                "pv.id",
                "p.product_type as product_type",
                DB::raw("(SELECT pvss.store_id FROM product_variant_store_stocks pvss WHERE pvss.product_variant_id = pv.id AND pvss.is_listed = 1 ORDER BY pvss.id LIMIT 1) as store_id"),
                "pv.name as product_name",
                DB::raw("COALESCE((SELECT pvss2.is_unlimited_stock FROM product_variant_store_stocks pvss2 WHERE pvss2.product_variant_id = pv.id AND pvss2.is_listed = 1 ORDER BY pvss2.id LIMIT 1), 0) as is_unlimited_stock"),
                "p.cancelable_status",
                "p.till_status_quick",
                "p.till_status_ecommerce",
                "p.return_status",
                "p.return_days",
                "p.gst_rate",
                "p.gst_inclusive",
                "p.hsn_code",
                DB::raw("(SELECT t.title FROM taxes t WHERE t.id = p.tax_id) as tax_title"),
                DB::raw("(SELECT t.percentage FROM taxes t WHERE t.id = p.tax_id) as tax_percentage")
            )
                ->from("product_variants as pv")
                ->leftJoin("products as p", "pv.product_id", "=", "p.id")
                ->whereIn("pv.id", $arr)
                ->orderByRaw("FIELD(pv.id, " . implode(',', $arr) . ")")
                ->get();

            if (!empty($variants)) {
                return $variants;
            }
        }
    }

    public static function getUserAddress($id)
    {
        $address = UserAddress::where("id", $id)->first();
        return $address;
    }

    /**
     * Wallet balance is country-wise now (one user_wallets row per user+country).
     * $country_id falls back to the user's registration country when omitted.
     */
    protected static function resolveWalletCountryId($userId, $country_id = null)
    {
        if (!empty($country_id)) {
            return (int) $country_id;
        }
        return (int) (User::where('id', $userId)->value('country_id') ?? 0);
    }

    /**
     * Wallet recharge is credited to the CURRENT-LOCATION country. We tag the recharge
     * identifier that round-trips through each gateway with a `-c{countryId}` marker so
     * server IPNs can recover it. Returns the suffix (empty when no country).
     */
    public static function rechargeCountrySuffix($countryId): string
    {
        return $countryId ? '-c' . (int) $countryId : '';
    }

    /**
     * Recover the recharge country id from a gateway identifier (string or the already
     * exploded parts). Looks for the `c{digits}` marker; returns null when absent
     * (legacy ids) so callers fall back to the user's home country.
     */
    public static function parseRechargeCountryId($idOrParts): ?int
    {
        $parts = is_array($idOrParts) ? $idOrParts : explode('-', (string) $idOrParts);
        foreach ($parts as $p) {
            if (preg_match('/^c(\d+)$/', (string) $p, $m)) {
                return (int) $m[1];
            }
        }
        return null;
    }

    /**
     * Resolve the wallet + currency snapshot for a recharge credit. Falls back to the
     * user's home country when no country was captured. Returns
     * ['country_id','currency','currency_code'] for stamping the wallet transaction.
     */
    public static function rechargeWalletMeta($countryId, $userId): array
    {
        $countryId = self::resolveWalletCountryId($userId, $countryId);
        $country = $countryId ? Country::find($countryId) : null;
        return [
            'country_id'    => $countryId ?: null,
            'currency'      => $country->currency ?? null,
            'currency_code' => $country->currency_code ?? null,
        ];
    }

    public static function addUserWalletBalance($amount, $id, $country_id = null)
    {
        $country_id = self::resolveWalletCountryId($id, $country_id);
        $wallet = UserWallet::firstOrNew(['user_id' => $id, 'country_id' => $country_id]);
        $wallet->balance = (float) ($wallet->balance ?? 0) + (float) $amount;
        $wallet->save();
        return $wallet->balance;
    }

    public static function updateUserWalletBalance($new_balance, $id, $country_id = null)
    {
        $country_id = self::resolveWalletCountryId($id, $country_id);
        $wallet = UserWallet::firstOrNew(['user_id' => $id, 'country_id' => $country_id]);
        $wallet->balance = (float) $new_balance;
        $wallet->save();
    }

    public static function getUserWalletBalance($id, $country_id = null)
    {
        $country_id = self::resolveWalletCountryId($id, $country_id);
        return (float) (UserWallet::where('user_id', $id)->where('country_id', $country_id)->value('balance') ?? 0);
    }

    /** All of a user's country wallets (country + currency + balance) for profile display. */
    public static function getUserWallets($id): array
    {
        return UserWallet::where('user_wallets.user_id', $id)
            ->join('countries', 'countries.id', '=', 'user_wallets.country_id')
            ->orderBy('countries.id')
            ->get([
                'user_wallets.country_id',
                'countries.name as country_name',
                'countries.currency',
                'countries.currency_code',
                'countries.logo as country_logo',
                'user_wallets.balance',
            ])->map(fn ($w) => [
                'country_id'    => (int) $w->country_id,
                'country_name'  => $w->country_name,
                'currency'      => $w->currency,
                'currency_code' => $w->currency_code,
                'country_logo'  => $w->country_logo ? asset('storage/' . $w->country_logo) : null,
                'balance'       => (float) $w->balance,
            ])->values()->all();
    }

    public static function addWalletTransaction($order_id, $order_item_id, $user_id, $type, $wallet_balance, $mesage, $status = 1, $payment_type = '')
    {
        $transaction = new WalletTransaction();
        $transaction->order_id = $order_id;
        $transaction->order_item_id     = $order_item_id;
        $transaction->user_id = $user_id;
        $transaction->type = $type;
        $transaction->amount = $wallet_balance;
        $transaction->message = $mesage;
        $transaction->status = $status;
        if (!empty($payment_type)) {
            $transaction->payment_type = $payment_type;
        }
        // Carry the order's currency + country/zone onto the wallet transaction.
        if (!empty($order_id)) {
            $order = Order::select('currency', 'currency_code', 'country_id', 'zone_id')->find($order_id);
            if ($order) {
                $transaction->currency = $order->currency;
                $transaction->currency_code = $order->currency_code;
                $transaction->country_id = $order->country_id;
                $transaction->zone_id = $order->zone_id;
            }
        }
        $transaction->save();

        if ($transaction->id) {
            return $transaction;
        } else {
            return false;
        }
    }

    public static function translateTransactionMessage($message)
    {
        if ($message === null || $message === '') {
            return $message;
        }
        $msg = (string) $message;
        if (!preg_match('/^[a-z0-9_]+$/', $msg)) {
            return $msg;
        }
        $previousLocale = App::getLocale();
        $defaultLocale = config('app.fallback_locale', 'en');
        $useLocale = $previousLocale;
        if (app()->has('request') && request()) {
            $header = request()->header('Content-Language');
            $code = $header ? trim(strtolower($header)) : '';
            if ($code !== '') {
                $langJson = resource_path('lang/' . $code . '.json');
                $langDir  = resource_path('lang/' . $code);
                if (File::exists($langJson) || File::isDirectory($langDir)) {
                    $useLocale = $code;
                }
            }
        }
        App::setLocale($useLocale);
        $text = __($msg);
        if ($text === $msg && $useLocale !== $defaultLocale) {
            App::setLocale($defaultLocale);
            $text = __($msg);
        }
        App::setLocale($previousLocale);
        return $text;
    }

    public static function isDeliverable($zone_id, $latitudeTo, $longitudeTo)
    {
        $point = ['lat' => $latitudeTo, 'lng' => $longitudeTo];

        $checkZoneIds = explode(',', $zone_id);
        $zones = Zone::whereIn('id', $checkZoneIds)->where('status', 1)->get();

        foreach ($zones as $zone) {
            foreach ([$zone->polygon_boundary_quick, $zone->polygon_boundary_ecommerce] as $polygon) {
                if (is_array($polygon) && !empty($polygon) && self::isPointInPolygon($point, $polygon)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function isDeliverableOrder($latitude, $longitude, $store_id, $channel = null)
    {
        if (!empty($store_id) || $store_id != "") {

            $city = self::getDeliverableCity($latitude, $longitude, $channel);

            if (!empty($city)) {
                return true;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    public static function isOrderItemCancelled($order_item_id)
    {
        // Use the snapshotted policy on the order item (frozen at purchase time).
        $order_item = OrderItem::select('cancelable_status')
            ->where("id", $order_item_id)
            ->first();

        if ($order_item->cancelable_status == OrderStatusList::$cancelled) {
            return true;
        } else {
            return false;
        }
    }
    public static function isOrderItemReturned($active_status, $postStatus)
    {
        if ($active_status != OrderStatusList::$delivered && $postStatus == OrderStatusList::$returned) {
            return true;
        } else {
            return false;
        }
    }
    public static function getImage($image)
    {
        if ($image) {
            return asset('storage/' . $image);
        } else {
            return '';
        }
    }

    public static function doubleNumber($number)
    {
        $formattedNumber = number_format($number, 2);
        $floatNumber = (float) str_replace(',', '', $formattedNumber);
        return $floatNumber;
    }

    public static function getProductVariant($variant_id, $user_id = null, $store_id = null)
    {
        $variant = ProductVariant::select(
            '*',
            // Per-store now: for a known store read that store's row, otherwise fall back to
            // "unlimited anywhere" (MAX across the variant's store rows).
            $store_id
                ? DB::raw("COALESCE((SELECT pvss_u.is_unlimited_stock FROM product_variant_store_stocks pvss_u WHERE pvss_u.product_variant_id = pv.id AND pvss_u.store_id = " . (int) $store_id . "), 0) as is_unlimited_stock")
                : DB::raw("COALESCE((SELECT MAX(pvss_u.is_unlimited_stock) FROM product_variant_store_stocks pvss_u WHERE pvss_u.product_variant_id = pv.id), 0) as is_unlimited_stock")
        )
            ->from('product_variants as pv')
            ->where('id', $variant_id)
            ->first();

        if ($variant) {
            $variant = $variant->makeHidden([
                'product_id',
                'deleted_at',
                'order_counter'
            ]);

            // Price is per-store (PVSS): copy the store's pricing onto the variant
            // (no store → cheapest listed store, as a representative "from" price).
            ProductHelper::applyStorePrice($variant, $store_id);

            $variant['cart_count'] = 0;
            if ($user_id) {
                $cart = Cart::where('product_variant_id', $variant['id'])->where('user_id', $user_id)->first();
                if ($cart) {
                    $variant['cart_count'] = $cart['qty'];
                }
            }

            $taxed = ProductHelper::getTaxableAmount($variant['id'], $store_id);

            $variant['discounted_price'] = CommonHelper::doubleNumber($taxed->taxable_discounted_price ?? $variant['discounted_price']);
            $variant['price'] = CommonHelper::doubleNumber($taxed->taxable_price ?? $variant['price']);
            $variant['taxable_amount'] = CommonHelper::doubleNumber($taxed->taxable_amount);

            $variant['stock_unit_name'] = $variant['stock_unit_name'] ?? '';

            // Safely calculate calc_discount_percentage
            if (!empty($taxed->taxable_price) && $taxed->taxable_price > 0) {
                $discount = ($taxed->taxable_price - $taxed->taxable_discounted_price);
                $variant['calc_discount_percentage'] = round(($discount / $taxed->taxable_price) * 100, 2);
            } else {
                $discount = ($variant['price'] -  $variant['discounted_price']);
                $variant['calc_discount_percentage'] = round(($discount / $variant['price']) * 100, 2);
            }

            return $variant;
        }

        return null;
    }


    public static function setOrderStatus($order_status)
    {
        $order_status['created_at'] = Carbon::now()->format('Y-m-d H:i:s');
        OrderStatus::create($order_status);
    }

    /* ----------------------------------------------------------------------
     * Online-payment order finalisation (shared by every gateway webhook +
     * the client-side addTransaction). Online orders keep their stock RESERVED
     * from placement; when the gateway confirms payment we commit the reservation
     * (reserved -= qty) and consume the cart. COD / wallet orders settle at
     * placement and never reach here.
     * -------------------------------------------------------------------- */

    /** Remove an order's lines from the buyer's cart (direct delete — no stock release). */
    public static function clearOrderCart($order): void
    {
        if (empty($order)) {
            return;
        }
        $variantIds = OrderItem::where('order_id', $order->id)->pluck('product_variant_id')->all();
        if (empty($variantIds)) {
            return;
        }
        Cart::where('user_id', $order->user_id)
            ->whereIn('product_variant_id', $variantIds)
            ->where(function ($q) use ($order) {
                $q->where('channel', $order->channel)->orWhereNull('channel');
            })
            ->delete();
    }

    /** Online payment CONFIRMED: commit each line's reservation + consume the cart. */
    public static function finalizeOnlineOrderSuccess($order): void
    {
        if (empty($order)) {
            return;
        }
        foreach (OrderItem::where('order_id', $order->id)->get(['product_variant_id', 'store_id', 'quantity']) as $item) {
            ProductHelper::commitReservedStock($item->product_variant_id, $item->store_id, (int) $item->quantity);
        }
        self::clearOrderCart($order);
        // Placement only seeded payment_pending; log the received transition on the timeline.
        self::recordOrderReceivedTimeline($order);
    }

    public static function recordOrderReceivedTimeline($order): void
    {
        if (empty($order)) {
            return;
        }
        $received = (int) OrderStatusList::$received;

        // Order-level row (order_item_id = 0).
        if (!OrderStatus::where('order_id', $order->id)->where('order_item_id', 0)->where('status', $received)->exists()) {
            self::setOrderStatus([
                'order_id'      => $order->id,
                'order_item_id' => 0,
                'status'        => $received,
                'created_by'    => $order->user_id,
                'user_type'     => OrderStatus::$userTypeUser,
            ]);
        }

        // Ecommerce is item-wise: each non-cancelled item gets its own received row.
        if ($order->channel === 'ecommerce') {
            $excluded = [(int) OrderStatusList::$cancelled, (int) OrderStatusList::$returned];
            $items = OrderItem::where('order_id', $order->id)->whereNotIn('active_status', $excluded)->get(['id']);
            foreach ($items as $item) {
                if (!OrderStatus::where('order_id', $order->id)->where('order_item_id', $item->id)->where('status', $received)->exists()) {
                    self::setOrderStatus([
                        'order_id'      => $order->id,
                        'order_item_id' => $item->id,
                        'status'        => $received,
                        'created_by'    => $order->user_id,
                        'user_type'     => OrderStatus::$userTypeUser,
                    ]);
                }
            }
        }
    }

    public static function getCartCount($user_id, $channel = null)
    {
        $channelFilter = function ($q) use ($channel) {
            if (in_array($channel, ['quick', 'ecommerce'], true)) {
                $q->where(function ($w) use ($channel) {
                    $w->where('carts.channel', $channel)->orWhereNull('carts.channel');
                });
            }
        };

        $total = Cart::select(DB::raw('COUNT(carts.id) AS cart_items_count'), DB::raw('sum(carts.qty) AS cart_total_qty'))
            ->Join('products', 'carts.product_id', '=', 'products.id')
            ->Join('product_variants', 'carts.product_variant_id', '=', 'product_variants.id')
            ->where('user_id', $user_id)
            ->where($channelFilter)
            ->first();
        $total->cart_items_count = intval($total->cart_items_count);
        $total->cart_total_qty = intval($total->cart_total_qty);

        $carts = Cart::select('carts.qty', 'carts.product_variant_id', 'carts.store_id')
            ->Join('products', 'carts.product_id', '=', 'products.id')
            ->Join('product_variants', 'carts.product_variant_id', '=', 'product_variants.id')
            ->where('user_id', '=', $user_id)
            ->where($channelFilter)
            ->get();

        $variant_ids = array_column($carts->toArray(), 'product_variant_id');
        $quantityArray = array_column($carts->toArray(), 'qty');
        // Price is per-store (PVSS): each cart line carries its fulfilling store.
        $storeArray = array_column($carts->toArray(), 'store_id');

        $totalAmt = CommonHelper::calculateTotalAmount($variant_ids, $quantityArray, $storeArray);

        $total->save_price = $totalAmt['save_price'];
        $total->sub_total = $totalAmt['sub_total']; // Base price without tax
        $total->total_amount = $totalAmt['total_amount']; // Final amount with tax

        $total->product_variant_id = implode(',', $variant_ids);
        $total->quantity = implode(',', $quantityArray);

        return $total;
    }

    public static function calculateTotalAmount($variant_ids, $quantityArray, $storeArray = [])
    {
        $save_price = 0;
        $total_amount = 0;
        $sub_total = 0; // NEW: base price without tax
        if (count($variant_ids) === count($quantityArray)) {
            foreach ($variant_ids as $key => $variant_id) {
                // Price is per-store (PVSS): resolve against the line's fulfilling store.
                $store_id = $storeArray[$key] ?? null;

                $taxed_amount = ProductHelper::getTaxableAmount($variant_id, $store_id);
                if (!$taxed_amount) {
                    continue;
                }
                // PVSS pricing row carries price / discounted_price / pricing_slabs.
                $variant = ProductHelper::storePriceRow($variant_id, $store_id);

                $qty = intval($quantityArray[$key]);
                $taxPct = floatval($taxed_amount->percentage ?? 0);
                $gstInclusive = (bool) ($taxed_amount->gst_inclusive ?? false);

                // Slab-aware effective pre-tax unit price (slab match → discounted → base).
                $unit = ProductHelper::slabUnitPrice($variant, $qty);
                
                // Base price (before tax)
                $sub_total += floatval($unit) * $qty;
                
                // IMPORTANT: Respect GST inclusive flag
                if ($gstInclusive) {
                    // GST Inclusive: Price already includes GST, don't add tax again
                    $unitWithTax = $unit;
                } else {
                    // GST Exclusive: Add GST on top of price
                    $unitWithTax = $unit + ($unit * $taxPct / 100);
                }

                $total_amount += floatval($unitWithTax) * $qty;

                $save_price += floatval($taxed_amount->price) * $qty;
            }
        }
        return array('save_price' => $save_price, 'total_amount' => $total_amount, 'sub_total' => $sub_total);
    }

    public static function calculateOrderTotalTax($item_details, $quantityArray)
    {
        $order_total_tax_amt = 0;
        $order_total_tax_per = 0;
        foreach ($item_details as $key => $item) {
            $quantity = $quantityArray[$key];
            $tax_percentage = (empty($item->tax_percentage) || ($item->tax_percentage == "")) ? 0 : $item->tax_percentage;
            // Slab-aware effective pre-tax unit price for this quantity.
            $unit = ProductHelper::slabUnitPrice($item, (int) $quantity);
            $final_price = $unit * $quantity;
            $tax_count = ($tax_percentage / 100) * $final_price;
            $order_total_tax_amt += $tax_count;
            $order_total_tax_per += $tax_percentage;
        }
        return array('order_total_tax_amt' => $order_total_tax_amt, 'order_total_tax_per' => $order_total_tax_per);
    }

    public static function findGoogleMapDistance($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo)
    {
        $origins = implode(",", [$latitudeFrom, $longitudeFrom]);
        $destinations = implode(",", [$latitudeTo, $longitudeTo]);
        $result = (new GoogleMaps)->findGoogleMapDistance($origins, $destinations);
        return $result;
    }

    /** Convert a distance in km to the zone's configured unit ('mi'/'mile' or 'km'). */
    public static function convertKmToUnit($km, $unit)
    {
        if ($km === null) {
            return null;
        }
        $u = strtolower(trim((string) $unit));
        return ($u === 'mi' || $u === 'mile') ? (float) $km * 0.621371 : (float) $km;
    }

    /** Normalize a zone distance unit to 'mi' or 'km' (default 'km'). Matches the admin select values. */
    public static function normalizeDistanceUnit($unit)
    {
        $u = strtolower(trim((string) $unit));
        return ($u === 'mi' || $u === 'mile') ? 'mi' : 'km';
    }

    /** Per-request memo of road distances so repeated store->customer lookups hit the API once. */
    protected static $roadDistanceCache = [];
    /** Separate per-request memo for OSRM-only lookups (values differ from the dynamic provider). */
    protected static $osrmDistanceCache = [];

    /**
     * Provider-aware ROAD distance in km (Google/OSRM per `map_provider`), memoized
     * within the request. Returns null only on hard provider failure; OSRM outages
     * already fall back to straight-line inside getDistanceData, so the cart/ETA is
     * never blocked. Use this for ETA/distance display instead of straightLineDistanceKm.
     */
    public static function roadDistanceKm($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo)
    {
        if ($latitudeFrom === null || $longitudeFrom === null || $latitudeTo === null || $longitudeTo === null) {
            return null;
        }
        $key = round((float) $latitudeFrom, 6) . ',' . round((float) $longitudeFrom, 6)
            . '|' . round((float) $latitudeTo, 6) . ',' . round((float) $longitudeTo, 6);
        if (array_key_exists($key, self::$roadDistanceCache)) {
            return self::$roadDistanceCache[$key];
        }
        $result = self::getDistanceData($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo);
        $km = !empty($result['error']) ? null : (float) ($result['distance_km'] ?? 0);
        return self::$roadDistanceCache[$key] = $km;
    }

    /**
     * OSRM-ONLY road distance in km, memoized within the request. Always uses the free
     * OSRM routing (never Google) regardless of `map_provider` — for high-volume,
     * informational ETAs (e.g. per-product listing delivery time) that must not spend
     * Google quota. Straight-line fallback on OSRM outage; null on missing coords.
     */
    public static function osrmDistanceKm($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo)
    {
        if ($latitudeFrom === null || $longitudeFrom === null || $latitudeTo === null || $longitudeTo === null) {
            return null;
        }
        $key = round((float) $latitudeFrom, 6) . ',' . round((float) $longitudeFrom, 6)
            . '|' . round((float) $latitudeTo, 6) . ',' . round((float) $longitudeTo, 6);
        if (array_key_exists($key, self::$osrmDistanceCache)) {
            return self::$osrmDistanceCache[$key];
        }
        $result = self::getOsrmDistance($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo);
        $km = !empty($result['error']) ? null : (float) ($result['distance_km'] ?? 0);
        return self::$osrmDistanceCache[$key] = $km;
    }

    public static function getDistanceData($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo)
    {
        $provider = strtolower(trim((string) Setting::get_value('map_provider'))) ?: 'osm';

        if ($provider === 'google') {
            $result = self::findGoogleMapDistance($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo);
            $body = $result['body'] ?? null;
            if (is_string($body)) {
                $body = json_decode($body, true);
            }

            if ((int) ($result['http_code'] ?? 0) !== 200 || !is_array($body)) {
                return self::distanceError($body['error_message'] ?? 'Unable to fetch distance');
            }
            if (($body['status'] ?? '') !== 'OK') {
                return self::distanceError($body['error_message'] ?? ($body['status'] ?? 'Distance error'));
            }
            $element = $body['rows'][0]['elements'][0] ?? null;
            if (!$element || ($element['status'] ?? '') !== 'OK') {
                return self::distanceError('Data not found or invalid. Please check!');
            }
            $meters = (float) ($element['distance']['value'] ?? 0);
            return [
                'error'         => false,
                'message'       => 'Data fetched successfully.',
                'distance_km'   => round($meters / 1000, 1),
                'distance_text' => $element['distance']['text'] ?? (round($meters / 1000, 1) . ' km'),
                'duration'      => $element['duration']['text'] ?? '',
            ];
        }

        // Default: OSM (OSRM road routing, straight-line fallback).
        return self::getOsrmDistance($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo);
    }

    /** OSRM driving distance; falls back to straight-line haversine when unreachable. */
    protected static function getOsrmDistance($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo)
    {
        $url = 'https://router.project-osrm.org/route/v1/driving/'
            . $longitudeFrom . ',' . $latitudeFrom . ';' . $longitudeTo . ',' . $latitudeTo
            . '?overview=false';

        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HTTPHEADER     => ['User-Agent: eCom/1.0'],
            ]);
            $raw  = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            unset($ch);

            $body = json_decode((string) $raw, true);
            if ($code === 200 && ($body['code'] ?? '') === 'Ok' && isset($body['routes'][0])) {
                $meters  = (float) ($body['routes'][0]['distance'] ?? 0);
                $seconds = (float) ($body['routes'][0]['duration'] ?? 0);
                $km      = round($meters / 1000, 1);
                return [
                    'error'         => false,
                    'message'       => 'Data fetched successfully.',
                    'distance_km'   => $km,
                    'distance_text' => $km . ' km',
                    'duration'      => self::formatDurationFromSeconds($seconds),
                ];
            }
        } catch (\Throwable $e) {
            // fall through to straight-line
        }

        // Straight-line fallback so delivery is never blocked by routing outages.
        $km = self::straightLineDistanceKm($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo);
        $km = $km === null ? 0 : round($km, 1);
        return [
            'error'         => false,
            'message'       => 'Straight-line distance (routing unavailable).',
            'distance_km'   => $km,
            'distance_text' => $km . ' km',
            'duration'      => '',
        ];
    }

    /** Normalized distance error payload. */
    protected static function distanceError($message)
    {
        return [
            'error'         => true,
            'message'       => $message,
            'distance_km'   => 0,
            'distance_text' => '0',
            'duration'      => '0',
        ];
    }

    /** Human-readable duration from seconds, e.g. "12 mins" / "1 hour 5 mins". */
    protected static function formatDurationFromSeconds($seconds)
    {
        $seconds = (int) $seconds;
        if ($seconds <= 0) {
            return '';
        }
        $mins  = (int) round($seconds / 60);
        if ($mins < 60) {
            return $mins . ' min' . ($mins === 1 ? '' : 's');
        }
        $hours = intdiv($mins, 60);
        $rem   = $mins % 60;
        $text  = $hours . ' hour' . ($hours === 1 ? '' : 's');
        if ($rem > 0) {
            $text .= ' ' . $rem . ' min' . ($rem === 1 ? '' : 's');
        }
        return $text;
    }

    public static function getDeliveryCharge($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo, $cityIdsString, $sub_total, $channel = null)
    {
        // Resolve zone whose polygon contains the customer point
        $zoneIds = array_filter(array_map('trim', explode(',', (string) $cityIdsString)));
        $zones = Zone::whereIn('id', $zoneIds)->where('status', 1)->get();
        $point = ['lat' => $latitudeFrom, 'lng' => $longitudeFrom];

        \Log::debug('[GET_DELIVERY_CHARGE]', [
            'zone_ids_requested' => $zoneIds,
            'customer_point' => $point,
            'store_location' => ['lat' => $latitudeTo, 'lng' => $longitudeTo],
            'zones_found' => $zones->count(),
            'channel' => $channel,
        ]);

        $matched = null;
        $hasPolygon = false;
        foreach ($zones as $zone) {
            $polygon = $zone->polygonFor($channel);
            
            \Log::debug('[ZONE_POLYGON_FOR]', [
                'zone_id' => $zone->id,
                'requested_channel' => $channel,
                'zone_sales_channel' => $zone->sales_channel,
                'has_polygon' => is_array($polygon) && !empty($polygon),
                'polygon_boundary_quick_exists' => !empty($zone->polygon_boundary_quick),
                'polygon_boundary_ecommerce_exists' => !empty($zone->polygon_boundary_ecommerce),
            ]);
            
            if (is_array($polygon) && !empty($polygon)) {
                $hasPolygon = true;
                $inPolygon = self::isPointInPolygon($point, $polygon);
                
                \Log::debug('[POINT_IN_POLYGON_CHECK]', [
                    'zone_id' => $zone->id,
                    'customer_in_polygon' => $inPolygon,
                ]);
                
                if ($inPolygon) {
                    $matched = $zone;
                    \Log::debug('[ZONE_MATCHED]', ['zone_id' => $zone->id]);
                    break;
                }
            }
        }

        if (!$matched && !$hasPolygon && !$zones->isEmpty()) {
            \Log::debug('[USING_FALLBACK]', [
                'zone_id' => $zones->first()->id,
                'reason' => 'no_polygon_defined',
            ]);
            $matched = $zones->first();
        }

        if (!$matched) {
            \Log::warning('[NO_ZONE_MATCHED]', [
                'zone_ids_requested' => $zoneIds,
                'zones_count' => $zones->count(),
                'has_polygon' => $hasPolygon,
            ]);
            $response['error'] = true;
            $response['message'] = 'Sorry, We are not delivering on selected address';
            return $response;
        }

        \Log::debug('[MATCHED_ZONE_DETAILS]', [
            'zone_id' => $matched->id,
            'zone_name' => $matched->name,
            'zone_sales_channel' => $matched->sales_channel,
        ]);

        if ($matched) {
            $priceChannel = in_array($channel, Zone::REAL_CHANNELS, true)
                ? $channel
                : ($matched->sales_channel === Zone::CHANNEL_BOTH ? Zone::CHANNEL_QUICK : $matched->sales_channel);

            // eCommerce prices by strategy (flat / slab / city / area), no distance call.
            if ($priceChannel === Zone::CHANNEL_ECOMMERCE) {
                $cityArea = self::resolveDeliveryCityArea($latitudeFrom, $longitudeFrom);
                $charge = self::ecommerceDeliveryCharge($matched, (float) $sub_total, $cityArea['delivery_city_id'], $cityArea['delivery_area_id']);

                // eCommerce supports flat additional charges too (no time-based surge).
                $extras = self::zoneSurgeAndAdditional($matched, $priceChannel);

                $response['error'] = false;
                $response['message'] = 'Data fetched successfully.';
                $response['charge'] = $charge;
                $response['distance'] = '0';
                $response['duration'] = '0';
                $response['surge_charges'] = [];
                $response['surge_total'] = 0;
                $response['additional_charges'] = $extras['additional_charges'];
                $response['additional_total'] = $extras['additional_total'];
                return $response;
            }

            // Quick zones: distance-based base + per-km. Time-window surge and flat zone
            // additional charges are returned separately (not added to this charge).
            $charge = (float) $matched->base_delivery_charge;
            $perKm = (float) $matched->charge_per_km;        // per zone distance unit
            $baseDistance = (float) $matched->base_distance; // in zone distance unit
            $freeAbove = (float) $matched->free_delivery_above;
            // base_distance / charge_per_km are expressed in the zone's distance unit
            // (km or mile), so the road distance (always km) is converted to that unit.
            $unit = self::normalizeDistanceUnit($matched->distance_unit ?? 'km');

            // Provider-aware distance (OSM/OSRM or Google per `map_provider` setting).
            $result = self::getDistanceData($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo);

            if (!empty($result['error'])) {
                $response['error'] = true;
                $response['message'] = $result['message'] ?? 'Unable to fetch distance';
                $response['charge'] = "0";
                $response['distance'] = "0";
                $response['distance_unit'] = $unit;
                $response['duration'] = "0";
                return $response;
            }

            $distance = self::convertKmToUnit((float) $result['distance_km'], $unit);

            if ($distance > $baseDistance) {
                $charge += ($distance - $baseDistance) * $perKm;
            }
            if ($freeAbove > 0 && $sub_total >= $freeAbove) {
                $charge = 0;
            }

            // Surge + zone additional charges are returned separately (displayed and
            // added to the total on their own) — NOT folded into the delivery charge.
            $extras = self::zoneSurgeAndAdditional($matched, $priceChannel);

            $response['error'] = false;
            $response['message'] = 'Data fetched successfully.';
            $response['charge'] = $charge;
            $response['distance'] = round($distance, 2) . ' ' . $unit;
            $response['distance_unit'] = $unit;
            $response['duration'] = $result['duration'];
            $response['surge_charges'] = $extras['surge_charges'];
            $response['surge_total'] = $extras['surge_total'];
            $response['additional_charges'] = $extras['additional_charges'];
            $response['additional_total'] = $extras['additional_total'];
            return $response;
        } else {
            $response['error'] = true;
            $response['message'] = 'Sorry, We are not delivering on selected address';
            return $response;
        }
    }

    /**
     * Resolve the customer's delivery city/area from a map pin by point-in-polygon
     * against delivery_areas / delivery_cities boundaries. Area is more specific and
     * carries its city. Returns ['delivery_city_id' => ?int, 'delivery_area_id' => ?int].
     */
    public static function resolveDeliveryCityArea($lat, $lng): array
    {
        $out = ['delivery_city_id' => null, 'delivery_area_id' => null];
        if ($lat === null || $lng === null) {
            return $out;
        }
        $point = ['lat' => $lat, 'lng' => $lng];

        foreach (DeliveryArea::where('status', 1)->whereNotNull('boundary_points')->get() as $area) {
            $poly = is_array($area->boundary_points) ? $area->boundary_points : json_decode($area->boundary_points, true);
            if (is_array($poly) && !empty($poly) && self::isPointInPolygon($point, $poly)) {
                $out['delivery_area_id'] = (int) $area->id;
                $out['delivery_city_id'] = (int) $area->delivery_city_id;
                return $out;
            }
        }
        foreach (DeliveryCity::where('status', 1)->whereNotNull('boundary_points')->get() as $city) {
            $poly = is_array($city->boundary_points) ? $city->boundary_points : json_decode($city->boundary_points, true);
            if (is_array($poly) && !empty($poly) && self::isPointInPolygon($point, $poly)) {
                $out['delivery_city_id'] = (int) $city->id;
                return $out;
            }
        }
        return $out;
    }

    /** eCommerce delivery charge for a zone by its pricing_strategy. */
    /** Ecommerce pricing fields live on the zone row itself. */
    private static function ecommerceDeliveryCharge($zone, float $sub_total, ?int $cityId, ?int $areaId): float
    {
        $strategy = $zone->pricing_strategy ?: 'flat';
        $charge = (float) ($zone->default_delivery_charge ?? 0);
        $free = false;

        if ($strategy === 'flat') {
            $charge = (float) $zone->flat_delivery_charge;
            if ((float) $zone->flat_free_delivery_above > 0 && $sub_total >= (float) $zone->flat_free_delivery_above) {
                $charge = 0;
                $free = true;
            }
        } elseif ($strategy === 'slab') {
            $matched = null;
            foreach ((is_array($zone->slab_pricing) ? $zone->slab_pricing : []) as $s) {
                $min = (float) ($s['min'] ?? 0);
                $max = ($s['max'] === '' || $s['max'] === null) ? INF : (float) $s['max'];
                if ($sub_total >= $min && $sub_total <= $max) {
                    $matched = $s;
                    break;
                }
            }
            $charge = $matched ? (float) ($matched['charge'] ?? 0) : (float) $zone->default_delivery_charge;
        } elseif ($strategy === 'city') {
            $row = self::matchPricingRow($zone->city_pricing, 'delivery_city_id', $cityId);
            $charge = $row ? (float) ($row['charge'] ?? 0) : (float) $zone->default_delivery_charge;
            if ($row && (float) ($row['free_above'] ?? 0) > 0 && $sub_total >= (float) $row['free_above']) {
                $charge = 0;
                $free = true;
            }
        } elseif ($strategy === 'area') {
            $row = self::matchPricingRow($zone->area_pricing, 'delivery_area_id', $areaId);
            $charge = $row ? (float) ($row['charge'] ?? 0) : (float) $zone->default_delivery_charge;
            if ($row && (float) ($row['free_above'] ?? 0) > 0 && $sub_total >= (float) $row['free_above']) {
                $charge = 0;
                $free = true;
            }
        }

        // Zone-wide free threshold (applies across strategies).
        if (!$free && (float) ($zone->free_delivery_threshold ?? 0) > 0 && $sub_total >= (float) $zone->free_delivery_threshold) {
            $charge = 0;
        }
        return max(0, $charge);
    }

    private static function matchPricingRow($rows, string $key, ?int $id): ?array
    {
        if ($id === null || !is_array($rows)) {
            return null;
        }
        foreach ($rows as $row) {
            if ((int) ($row[$key] ?? 0) === (int) $id) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Translated indexed-list field (surge_labels / additional_charge_names) with
     * PER-INDEX fallback. HasTranslations only falls back whole-array, so a label
     * filled in one language but blank in the current one would render blank.
     * Here each index resolves independently: current language → default language
     * → base row, first non-empty wins. Returns an index => string map.
     */
    private static function translatedListWithFallback($model, string $field): array
    {
        $toArray = function ($v) {
            if (is_array($v)) {
                return $v;
            }
            if (is_string($v) && $v !== '') {
                $decoded = json_decode($v, true);
                return is_array($decoded) ? $decoded : [];
            }
            return [];
        };

        $current = $toArray($model->translated($field));

        $default = [];
        $defaultLang = app(LanguageService::class)->getDefaultLanguage();
        if ($defaultLang) {
            $t = $model->translation((int) $defaultLang->id);
            if ($t) {
                $default = $toArray($t->$field ?? null);
            }
        }

        $base = $toArray($model->getAttributes()[$field] ?? null);

        $keys = array_keys($current + $default + $base);
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = '';
            foreach ([$current, $default, $base] as $src) {
                $val = $src[$k] ?? null;
                if ($val !== null && trim((string) $val) !== '') {
                    $out[$k] = $val;
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Active surge slots (time-window in system timezone) + flat zone additional
     * charges for a quick zone. Labels/names use the translated zone fields.
     * Returns surge_charges/surge_total + additional_charges/additional_total.
     */
    /**
     * Credit a delivery boy when an ecommerce order ITEM is delivered: bonus to
     * balance (+ fund transfer) and, for COD, the item's payable as cash collected
     * (+ a delivery-boy transaction). Idempotent via order_items.is_credited.
     */
    public static function creditOrderItemDelivery($item, $order = null): void
    {
        if (!($item instanceof OrderItem)) {
            $item = OrderItem::find($item);
        }
        if (!$item || (int) $item->active_status !== OrderStatusList::$delivered) {
            return;
        }
        if ((int) ($item->is_credited ?? 0) === 1 || !$item->delivery_boy_id) {
            return;
        }
        $order = $order ?: Order::find($item->order_id);
        if (!$order || $order->channel !== 'ecommerce') {
            return;
        }
        $deliveryBoy = DeliveryBoy::find($item->delivery_boy_id);
        if (!$deliveryBoy) {
            return;
        }

        $bonus = floatval($item->delivery_boy_bonus_amount);
        self::addDeliveryBoySettlement($deliveryBoy->id, $bonus, DeliveryBoySettlement::$typeCredit);
        $deliveryBoy->refresh();

        // COD: cash collected = item payable minus the wallet portion already paid.
        if ($order->payment_method == DeliveryBoyCashCollection::$paymentTypeCod) {
            $cashDue = max(0, floatval($item->final_total) - floatval($item->wallet_balance));
            DeliveryBoyCashCollection::create([
                'user_id'          => $order->user_id,
                'order_id'         => $order->id,
                'delivery_boy_id'  => $deliveryBoy->id,
                'type'             => $order->payment_method,
                'amount'           => $cashDue,
                'status'           => Transaction::$statusSuccess,
                'message'          => 'cod_collected_on_delivery',
                'transaction_date' => now(),
            ]);
            $deliveryBoy->cash_received = floatval($deliveryBoy->cash_received) + $cashDue;
        }
        $deliveryBoy->save();

        $item->is_credited = 1;
        $item->save();
    }

    /**
     * Ecommerce orders are managed item-wise; the order's active_status is derived
     * from its items: all cancelled → cancelled, otherwise the earliest stage among
     * the non-cancelled items (so "all delivered" yields delivered). No-op for quick.
     */
    public static function syncEcommerceOrderStatus($order): void
    {
        if (!($order instanceof Order)) {
            $order = Order::find($order);
        }
        if (!$order || $order->channel !== 'ecommerce') {
            return;
        }
        $statuses = OrderItem::where('order_id', $order->id)
            ->pluck('active_status')->map(fn ($s) => (int) $s);
        if ($statuses->isEmpty()) {
            return;
        }
        $nonCancelled = $statuses->reject(fn ($s) => $s === OrderStatusList::$cancelled)->values();
        // Status ids are sequential by flow (1..6), so min = earliest stage.
        $new = $nonCancelled->isEmpty() ? OrderStatusList::$cancelled : (int) $nonCancelled->min();
        $becameDelivered = ((int) $order->active_status !== $new) && $new === OrderStatusList::$delivered;
        if ((int) $order->active_status !== $new) {
            $order->active_status = $new;
            $order->save();
        }
        // On the transition to delivered, handle promo cashback (immediate or deferred).
        if ($becameDelivered) {
            self::handleEcommerceCashbackOnDelivery($order);
        }
    }

    /**
     * Ecommerce cashback on delivery: credit immediately when no item is returnable;
     * otherwise defer until the return window closes (then it's credited only if
     * nothing was returned). Quick orders credit directly via creditOrderCashback().
     */
    public static function handleEcommerceCashbackOnDelivery($order): void
    {
        if ((float) $order->cashback_amount <= 0 || (int) $order->cashback_credited === 1) {
            return;
        }

        // Returnable = a non-cancelled item with a return policy (frozen snapshot).
        $returnable = OrderItem::where('order_id', $order->id)
            ->where('active_status', '!=', OrderStatusList::$cancelled)
            ->where('return_status', 1)
            ->where('return_days', '>', 0)
            ->exists();

        if (!$returnable) {
            self::creditOrderCashback($order);
            return;
        }

        // Defer until the longest return window has elapsed.
        $maxReturnDays = (int) OrderItem::where('order_id', $order->id)
            ->where('active_status', '!=', OrderStatusList::$cancelled)
            ->where('return_status', 1)
            ->max('return_days');

        if ($maxReturnDays > 0) {
            \App\Jobs\ProcessOrderCashbackAfterReturnPeriod::dispatch($order->id)
                ->delay(now()->addDays($maxReturnDays));
        } else {
            self::creditOrderCashback($order);
        }
    }

    /**
     * Split a total across items by weight (item sub_totals), rounded to 2dp, with
     * the last item absorbing any rounding remainder so the parts sum exactly to
     * $total. Zero/empty weights fall back to an equal split.
     *
     * @param float[] $weights
     * @return float[] amounts aligned to $weights
     */
    public static function splitByWeight(array $weights, float $total): array
    {
        $n = count($weights);
        if ($n === 0) {
            return [];
        }
        $sumW = array_sum($weights);
        $out = [];
        if ($sumW <= 0) {
            $each = round($total / $n, 2);
            $out = array_fill(0, $n, $each);
        } else {
            foreach ($weights as $w) {
                $out[] = round($total * ((float) $w / $sumW), 2);
            }
        }
        // Last item absorbs the rounding remainder.
        $assignedExceptLast = array_sum(array_slice($out, 0, $n - 1));
        $out[$n - 1] = round($total - $assignedExceptLast, 2);
        return $out;
    }

    /**
     * Normalize an order's additional/surge charges (array or JSON string) and keep
     * only the rows flagged is_refundable. Rows missing the flag are treated as
     * refundable (legacy orders saved before the flag existed).
     */
    public static function refundableCharges($charges): array
    {
        if (is_string($charges)) {
            $charges = json_decode($charges, true);
        }
        if (!is_array($charges)) {
            return [];
        }
        return array_values(array_filter(
            $charges,
            fn ($c) => is_array($c) && ($c['is_refundable'] ?? true)
        ));
    }

    private static function zoneSurgeAndAdditional($zone, ?string $channel = null): array
    {
        // Surge slots are wall-clock times in the zone's country timezone.
        $now = Carbon::now(self::zoneTimezone($zone))->format('H:i');

        $surgeCharges = [];
        $surgeTotal = 0;
        // Per-index translated labels: current lang wins, empty index falls back to default lang.
        $labels = self::translatedListWithFallback($zone, 'surge_labels');
        foreach ((is_array($zone->surge_slots) ? $zone->surge_slots : []) as $i => $slot) {
            $start = isset($slot['start']) ? substr(trim((string) $slot['start']), 0, 5) : '';
            $end = isset($slot['end']) ? substr(trim((string) $slot['end']), 0, 5) : '';
            $charge = isset($slot['charge']) ? (float) $slot['charge'] : 0;
            if ($start === '' || $end === '' || $charge <= 0) {
                continue;
            }
            // Overnight slot (e.g. 22:00 -> 02:00) wraps midnight.
            $active = ($start <= $end) ? ($now >= $start && $now <= $end) : ($now >= $start || $now <= $end);
            if (!$active) {
                continue;
            }
            $label = (string) ($labels[$i] ?? '');
            if ($label === '') {
                $label = (string) ($slot['label'] ?? '');
            }
            $surgeCharges[] = ['label' => $label, 'charge' => $charge, 'is_refundable' => (bool) ($slot['is_refundable'] ?? false)];
            $surgeTotal += $charge;
        }

        $additionalCharges = [];
        $additionalTotal = 0;
        // Per-index translated names: current lang wins, empty index falls back to default lang.
        $nameField = $channel === Zone::CHANNEL_ECOMMERCE
            ? 'additional_charge_names_ecommerce'
            : 'additional_charge_names_quick';
        $names = self::translatedListWithFallback($zone, $nameField);
        foreach ($zone->additionalChargesFor($channel) as $i => $c) {
            $amount = isset($c['amount']) ? (float) $c['amount'] : 0;
            if ($amount <= 0) {
                continue;
            }
            $name = (string) ($names[$i] ?? '');
            if ($name === '') {
                $name = (string) ($c['name'] ?? '');
            }
            $additionalCharges[] = ['name' => $name, 'amount' => $amount, 'is_refundable' => (bool) ($c['is_refundable'] ?? false)];
            $additionalTotal += $amount;
        }

        return [
            'surge_charges'      => $surgeCharges,
            'surge_total'        => $surgeTotal,
            'additional_charges' => $additionalCharges,
            'additional_total'   => $additionalTotal,
        ];
    }

    public static function getAllDeliveryCharge($latitudeFrom, $longitudeFrom, $store_ids, $sub_total, $channel = null)
    {

        $sellers = Store::select('stores.id', 'stores.name', 'stores.latitude', 'stores.longitude', 'stores.zone_id', DB::raw("6371 * acos(cos(radians(" . $latitudeFrom . "))
                                * cos(radians(stores.latitude)) * cos(radians(stores.longitude) - radians(" . $longitudeFrom . "))
                                + sin(radians(" . $latitudeFrom . ")) * sin(radians(stores.latitude))) AS distance"))
            ->whereIn('stores.id', $store_ids)
            ->get();

        \Log::debug('[GET_ALL_DELIVERY_CHARGE]', [
            'customer_lat' => $latitudeFrom,
            'customer_lng' => $longitudeFrom,
            'store_ids' => $store_ids,
            'stores_found' => $sellers->count(),
            'channel' => $channel,
        ]);

        if ($sellers->isNotEmpty()) {

            $total_delivery_charge = 0;
            $surge_total = 0;
            $additional_total = 0;
            $surge_charges = [];
            $additional_charges = [];
            $distance_unit = 'km';
            $data = array();

            foreach ($sellers as $seller) {
                \Log::debug('[PROCESSING_STORE]', [
                    'store_id' => $seller->id,
                    'store_name' => $seller->name,
                    'store_zone_id' => $seller->zone_id,
                    'store_lat' => $seller->latitude,
                    'store_lng' => $seller->longitude,
                ]);

                $delivery = self::getDeliveryCharge($latitudeFrom, $longitudeFrom, $seller->latitude, $seller->longitude, $seller->zone_id, $sub_total, $channel);

                \Log::debug('[STORE_DELIVERY_RESULT]', [
                    'store_id' => $seller->id,
                    'delivery_error' => $delivery["error"] ?? false,
                    'delivery_charge' => $delivery["charge"] ?? 0,
                    'message' => $delivery["message"] ?? '',
                ]);

                if ($delivery["error"] == true) {
                    $response['status'] = 0;
                    $response['message'] =  $delivery["message"];
                    return $response;
                }

                $distance_unit = $delivery['distance_unit'] ?? $distance_unit;
                $data[] = [
                    'store_name'     => $seller->name,
                    'delivery_charge' => $delivery["charge"],
                    'distance'       => $delivery["distance"],
                    'distance_unit'  => $delivery['distance_unit'] ?? 'km',
                    'duration'       => $delivery["duration"],
                ];
                $total_delivery_charge += (float) $delivery["charge"];

                // Surge + zone additional charges (quick zones only; empty otherwise).
                $surge_total      += (float) ($delivery['surge_total'] ?? 0);
                $additional_total += (float) ($delivery['additional_total'] ?? 0);
                foreach (($delivery['surge_charges'] ?? []) as $s) {
                    $surge_charges[] = $s;
                }
                foreach (($delivery['additional_charges'] ?? []) as $a) {
                    $additional_charges[] = $a;
                }
            }

            $result = [
                'total_delivery_charge' => $total_delivery_charge,
                'store_info'            => $data,
                'distance_unit'         => $distance_unit,
                'surge_total'           => $surge_total,
                'surge_charges'         => $surge_charges,
                'additional_total'      => $additional_total,
                'additional_charges'    => $additional_charges,
            ];

            $response['status'] = 1;
            $response['message'] = 'Data fetched successfully.';
            $response['data'] = $result;
            return $response;
        } else {
            \Log::warning('[GET_ALL_DELIVERY_NO_STORES]', [
                'customer_lat' => $latitudeFrom,
                'customer_lng' => $longitudeFrom,
                'store_ids' => $store_ids,
            ]);
            $response['status'] = 0;
            $response['message'] =  __('sorry_we_are_not_delivering_on_selected_address');
            return $response;
        }
    }





    /**
     * Refund amount for a single order item. All amounts are read straight from the
     * order_items row, which already stores per-item values proportionally split at
     * placement (sub_total, promo_discount, delivery_charge, additional/surge charges).
     *   base = sub_total - promo_discount
     *        + refundable additional charges
     *        + refundable surge charges
     *        + delivery charge ONLY when $includeDeliveryCharge (true for cancel, false for return)
     * Non-refundable additional/surge charges are never refunded.
     */
    public static function computeOrderItemRefund($orderItem, bool $includeDeliveryCharge): float
    {
        if (!$orderItem) {
            return 0;
        }

        // Item value already net of its proportional promo share.
        $amount = floatval($orderItem->sub_total) - floatval($orderItem->promo_discount);

        // Refundable additional charges (json column on the item).
        $additional = self::refundableCharges($orderItem->additional_charges);
        $amount += array_sum(array_column($additional, 'amount'));

        // Refundable surge charges (json column on the item).
        $surge = self::refundableCharges($orderItem->surge_charges);
        $amount += array_sum(array_column($surge, 'charge'));

        // Delivery charge: refunded on cancel, withheld on return.
        if ($includeDeliveryCharge) {
            $amount += floatval($orderItem->delivery_charge);
        }

        return round(max(0, $amount), 2);
    }

    /** Record a return-request status change in its history (for the timeline). */
    public static function setReturnRequestStatus($returnRequestId, $status, $createdBy = null, $userType = null): void
    {
        ReturnRequestStatus::create([
            'return_request_id' => $returnRequestId,
            'status'            => $status,
            'created_by'        => $createdBy,
            'user_type'         => $userType,
        ]);
    }

    /**
     * Build the detailed return order item (product + order context) for a return
     * request: same shape used in the return detail / slider, with variant attributes,
     * the order item id, and the computed return refund amount.
     */
    /**
     * Format a user address into the one-line snapshot string used on orders/returns
     * (same layout as placeOrder): address landmark area city state country-pincode name mobile.
     */
    public static function formatUserAddress($a): string
    {
        if (!$a) {
            return '';
        }
        // Name + mobiles are stored separately in the address object, so the formatted
        // string holds only the location parts.
        return $a->address . ' ' . $a->landmark . ' ' . $a->area . ' ' . $a->city . ' ' . $a->state . ' ' . $a->country . '-' . $a->pincode;
    }

    /**
     * Address snapshot object stored (as JSON) on orders.address and
     * return_requests.address. Single source for name + formatted address + mobiles +
     * coordinates, so individual columns aren't needed.
     */
    public static function userAddressData($a): array
    {
        if (!$a) {
            return ['name' => '', 'address' => '', 'mobile' => '', 'alternate_mobile' => '', 'latitude' => null, 'longitude' => null];
        }
        $mobile = trim(($a->country_code ?? '') . ' ' . $a->mobile);
        $altMobile = !empty($a->alternate_mobile)
            ? trim(($a->alternate_country_code ?: ($a->country_code ?? '')) . ' ' . $a->alternate_mobile)
            : '';
        return [
            'name'             => $a->name ?? '',
            'address'          => self::formatUserAddress($a),
            'mobile'           => $mobile,
            'alternate_mobile' => $altMobile,
            'latitude'         => $a->latitude ?? null,
            'longitude'        => $a->longitude ?? null,
        ];
    }

    /**
     * Normalize a stored address value (JSON string or array) into the address object.
     * Returns null when empty.
     */
    public static function addressObject($raw): ?array
    {
        if (empty($raw)) {
            return null;
        }
        $data = is_array($raw) ? $raw : json_decode((string) $raw, true);
        // Legacy rows stored a plain formatted string.
        if (!is_array($data)) {
            return ['name' => '', 'address' => (string) $raw, 'mobile' => '', 'alternate_mobile' => '', 'latitude' => null, 'longitude' => null];
        }
        return [
            'name'             => $data['name'] ?? '',
            'address'          => $data['address'] ?? '',
            'mobile'           => $data['mobile'] ?? '',
            'alternate_mobile' => $data['alternate_mobile'] ?? '',
            'latitude'         => $data['latitude'] ?? null,
            'longitude'        => $data['longitude'] ?? null,
        ];
    }

    /**
     * store_info object for delivery boy / order APIs: translated store name
     * (current Content-Language, falling back to the base value) + location and
     * contact. Cached per request — call it per item without N+1 worry.
     */
    public static function storeInfo($storeId): ?array
    {
        static $cache = [];
        $storeId = (int) $storeId;
        if (!$storeId) {
            return null;
        }
        if (array_key_exists($storeId, $cache)) {
            return $cache[$storeId];
        }
        $store = Store::with('translations')->find($storeId);
        if (!$store) {
            return $cache[$storeId] = null;
        }
        return $cache[$storeId] = [
            'store_name'        => $store->getTranslatedAttribute('name') ?? $store->name,
            'latitude'          => $store->latitude,
            'longitude'         => $store->longitude,
            'mobile'            => $store->contact_number ?? '',
            'formatted_address' => $store->formatted_address ?? '',
        ];
    }

    public static function buildReturnItem($orderId, $orderItemId)
    {
        $returnItem = Order::select(
            'order_items.*',
            'orders.mobile',
            'orders.total',
            'orders.delivery_charge',
            'orders.discount',
            'orders.promo_code',
            'orders.promo_discount',
            'orders.wallet_balance',
            'orders.final_total',
            'orders.payment_method',
            'orders.address',
            'users.name as user_name',
            'stores.name as store_name',
            'products.id as product_id',
            'products.return_status',
            'products.return_days',
            DB::raw('CONCAT("' . asset('storage/') . '", "/", products.image) as image'),
            'os.id as active_status',
            'os.status as status_name'
        )
            ->leftJoin('order_items', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('users', 'orders.user_id', '=', 'users.id')
            ->leftJoin('product_variants', 'order_items.product_variant_id', '=', 'product_variants.id')
            ->leftJoin('products', 'product_variants.product_id', '=', 'products.id')
            ->leftJoin('stores', 'order_items.store_id', '=', 'stores.id')
            ->leftJoin('order_status_lists as os', 'order_items.active_status', '=', 'os.id')
            ->where('orders.id', $orderId)
            ->where('order_items.id', $orderItemId)
            ->first();

        if ($returnItem) {
            $tax_amount = (float) ($returnItem->tax_amount ?? 0);
            $returnItem->price = (float) self::doubleNumber($returnItem->price + $tax_amount);
            // discounted_price: DB value (+tax) when set, else fall back to the selling price.
            $returnItem->discounted_price = (float) self::doubleNumber(
                ($returnItem->discounted_price != 0 ? $returnItem->discounted_price + $tax_amount : $returnItem->price)
            );
            $returnItem->sub_total   = (float) ($returnItem->sub_total ?? 0);
            $returnItem->final_total = (float) ($returnItem->final_total ?? 0);
            $returnItem->makeHidden(['created_at', 'updated_at', 'deleted_at', 'delivery_boy_bonus_details', 'address']);

            // Drop the legacy per-item status JSON snapshot (superseded by timeline).
            unset($returnItem->status);

            // Expose the order item id + its variant attributes (attribute names + values).
            $returnItem->id = (int) $orderItemId;
            $va = $returnItem->variant_attributes;
            $returnItem->variant_attributes = is_string($va) ? (json_decode($va, true) ?: []) : (is_array($va) ? $va : []);

            // Return refund excludes delivery (use the real item row).
            $refundItem = OrderItem::find($orderItemId);
            $returnItem->amount_to_refund = self::computeOrderItemRefund($refundItem, false);
        }

        return $returnItem;
    }

    /** Resolve the "updated by" name for a return-status history row (admins vs users). */
    public static function returnTimelineUpdaterName($row, $userNames, $adminNames): string
    {
        if (!$row->created_by) {
            return '';
        }
        return is_null($row->user_type)
            ? (string) ($userNames[$row->created_by] ?? '')
            : (string) ($adminNames[$row->created_by] ?? '');
    }

    /** Build the translated status timeline for a return request. */
    public static function getReturnRequestTimeline($returnRequestId): array
    {
        $rows = ReturnRequestStatus::where('return_request_id', $returnRequestId)
            ->orderBy('id', 'ASC')
            ->get();

        // user_type null = customer (users table); otherwise an admin/super-admin/
        // delivery boy (admins table). Resolve names from the correct table.
        $userNames = User::whereIn('id', $rows->whereNull('user_type')->pluck('created_by')->filter()->unique())->pluck('name', 'id');
        $adminNames = Admin::whereIn('id', $rows->whereNotNull('user_type')->pluck('created_by')->filter()->unique())->pluck('username', 'id');

        return $rows->map(fn ($s) => [
            'status'      => (int) $s->status,
            'status_name' => ReturnStatusList::getTranslatedName((int) $s->status),
            'updated_by'  => self::returnTimelineUpdaterName($s, $userNames, $adminNames),
            'datetime'    => $s->created_at,
        ])->values()->all();
    }

    public static function formatDateTimeForCountry($utcDateTime, ?string $timezone, ?string $dateFormat, ?string $timeFormat): string
    {
        if (empty($utcDateTime)) {
            return '';
        }
        $tz = ($timezone !== null && trim($timezone) !== '') ? trim($timezone) : config('app.timezone', 'UTC');
        $dateFormat = ($dateFormat !== null && trim($dateFormat) !== '') ? trim($dateFormat) : 'd M Y';
        $timeFormat = ($timeFormat !== null && trim($timeFormat) !== '') ? trim($timeFormat) : 'h:i A';
        try {
            return \Carbon\Carbon::parse($utcDateTime, 'UTC')
                ->setTimezone($tz)
                ->format($dateFormat . ' ' . $timeFormat);
        } catch (\Throwable $e) {
            // Bad timezone/format string — degrade to the raw stored value.
            return (string) $utcDateTime;
        }
    }

    public static function getOrderDetails($order_id, $exclude_cancelled_returned = false)
    {
        $order = Order::select(
            'orders.*',
            'orders.additional_charges',
            'orders.id as order_id',
            'orders.created_at as orders_created_at',
            'users.name as user_name',
            'users.email as user_email',
            'users.mobile as user_mobile',
            'users.country_code as user_country_code',
            'stores.id as store_id',
            'stores.name as store_name',
            'stores.email as store_email',
            'stores.formatted_address as store_formatted_address',
            'stores.latitude as store_latitude',
            'stores.longitude as store_longitude',
            'delivery_boys.name as delivery_boy_name',
            'order_items.id as order_item_id',
            'os.id as active_status',
            'os.status as status_name',
            'countries.timezone as country_timezone',
            'countries.date_format as country_date_format',
            'countries.time_format as country_time_format'
        )
            ->leftJoin('order_items', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('users', 'orders.user_id', '=', 'users.id')
            ->leftJoin('product_variants', 'order_items.product_variant_id', '=', 'product_variants.id')
            ->leftJoin('products', 'product_variants.product_id', '=', 'products.id')
            ->leftJoin('delivery_boys', 'orders.delivery_boy_id', '=', 'delivery_boys.id')
            ->leftJoin('stores', 'order_items.store_id', '=', 'stores.id')
            ->leftJoin('countries', 'orders.country_id', '=', 'countries.id')
            ->leftJoin('order_status_lists as os', 'orders.active_status', '=', 'os.id')
            ->where('orders.id', $order_id)
            ->groupBy('orders.id')
            ->first();
        if ($order) {
            $order->additional_charges = is_string($order->additional_charges)
                ? json_decode($order->additional_charges ?: "[]")
                : ($order->additional_charges ?? []);
            $order->surge_charges = is_string($order->surge_charges)
                ? json_decode($order->surge_charges ?: "[]")
                : ($order->surge_charges ?? []);

            $order->orders_created_at_local = self::formatDateTimeForCountry(
                $order->orders_created_at,
                $order->country_timezone ?? null,
                $order->country_date_format ?? null,
                $order->country_time_format ?? null
            );
        }

        $order_items_query = Order::select(
            'order_items.*',
            'orders.mobile',
            'orders.total as order_total',
            'orders.delivery_charge as order_delivery_charge',
            'orders.discount',
            'orders.promo_code',
            'orders.promo_discount as order_promo_discount',
            'orders.wallet_balance as order_wallet_balance',
            'orders.final_total as order_final_total',
            'orders.payment_method',
            'users.name as user_name',
            'order_items.status as order_status',
            'stores.name as store_name',
            'products.id as product_id',
            'products.image as product_image',
            'order_items.return_status',
            'order_items.return_days',
            // Removed image concatenation - will use product model accessor instead
            // Rows hydrate as Order models here, so OrderItem's prescription_url accessor
            // never fires — build the URL in SQL like before.
            DB::raw('CASE WHEN order_items.prescription IS NULL OR order_items.prescription = "" THEN "" ELSE CONCAT("' . asset('storage/') . '", "/", order_items.prescription) END as prescription_url'),
            'os.id as active_status',
            'os.status as status_name'
        )
            ->leftJoin('order_items', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('users', 'orders.user_id', '=', 'users.id')
            ->leftJoin('product_variants', 'order_items.product_variant_id', '=', 'product_variants.id')
            ->leftJoin('products', 'product_variants.product_id', '=', 'products.id')
            ->leftJoin('stores', 'order_items.store_id', '=', 'stores.id')
            ->leftJoin('order_status_lists as os', 'order_items.active_status', '=', 'os.id')
            ->where('orders.id', $order_id);

        if ($exclude_cancelled_returned) {
            $order_items_query->whereNotIn('order_items.active_status', [7, 8]);
        }

        $order_items = $order_items_query->orderBy('order_items.id', 'DESC')->get();

        // Add is_product_returned flag to each order item if product is requested for return
        $order_item_ids = $order_items->pluck('id')->toArray();
        if (!empty($order_item_ids)) {
            $returnRequests = ReturnRequest::where('order_id', $order_id)
                ->whereIn('order_item_id', $order_item_ids)
                ->pluck('order_item_id')
                ->toArray();

            foreach ($order_items as $order_item) {
                $order_item->is_product_returned = in_array($order_item->id, $returnRequests) ? 1 : 0;
            }
        } else {
            foreach ($order_items as $order_item) {
                $order_item->is_product_returned = 0;
            }
        }

        foreach ($order_items as $order_item) {
            $va = $order_item->variant_attributes ?? null;
            $order_item->variant_attributes = is_string($va) ? (json_decode($va, true) ?: []) : (is_array($va) ? $va : []);
            // discounted_price: DB value when > 0, else fall back to the base price.
            $order_item->discounted_price = ((float) $order_item->discounted_price > 0)
                ? (float) $order_item->discounted_price
                : (float) $order_item->price;
            // Decode per-item charge JSON (ecommerce item-wise amounts).
            foreach (['additional_charges', 'surge_charges', 'delivery_boy_bonus_details'] as $jf) {
                $v = $order_item->{$jf} ?? null;
                $order_item->{$jf} = is_string($v) ? (json_decode($v, true) ?: []) : (is_array($v) ? $v : []);
            }
            
            // Generate image_url properly handling Cloudinary URLs
            if (!empty($order_item->product_image)) {
                // If image is a full URL (Cloudinary), use as-is
                if (preg_match('~^https?://~', $order_item->product_image)) {
                    $order_item->image_url = $order_item->product_image;
                } else {
                    // Otherwise treat as local storage path
                    $order_item->image_url = asset('storage/' . $order_item->product_image);
                }
            } else {
                $order_item->image_url = '';
            }
            // Keep legacy 'image' field for backward compatibility
            $order_item->image = $order_item->image_url;
        }

        // Add seller, delivery boy, status translations per order item and order.
        // If Content-Language header is passed: single-language format { lang, name }.
        // If not passed: all-language format { en: "...", hi: "..." } for admin panel.
        if ($order_items->isNotEmpty()) {
            $contentLanguage = request() ? request()->header('Content-Language') : null;
            $useContentLanguage = $contentLanguage !== null && trim((string) $contentLanguage) !== '';

            $languageService = app(LanguageService::class);
            $activeLangCodes = collect($languageService->getActiveLanguages())->pluck('code')->filter()->values()->all();
            $defaultLang = $languageService->getDefaultLanguage();
            $defaultCode = $defaultLang ? $languageService->getLanguageCode($defaultLang->id) : 'en';

            if ($useContentLanguage) {
                // Single language: Content-Language passed
                $langCode = LanguageService::getCurrentCode() ?? 'en';
                $storeIds = array_unique(array_merge(
                    $order_items->pluck('store_id')->filter()->unique()->values()->toArray(),
                    $order && isset($order->store_id) ? [$order->store_id] : []
                ));
                $sellerNameMap = [];
                $storeNameMap = [];
                if (!empty($storeIds)) {
                    foreach (Store::whereIn('id', $storeIds)->with('translations')->get(['id', 'name']) as $s) {
                        $sellerNameMap[$s->id] = ['lang' => $langCode, 'name' => $s->name ?? ''];
                        $storeNameMap[$s->id] = ['lang' => $langCode, 'name' => $s->name ?? ''];
                    }
                }
                $deliveryBoyIds = $order && ($order->delivery_boy_id ?? 0) ? [$order->delivery_boy_id] : [];
                $deliveryBoyNameMap = [];
                if (!empty($deliveryBoyIds)) {
                    foreach (DeliveryBoy::whereIn('id', $deliveryBoyIds)->with('translations')->get(['id', 'name']) as $db) {
                        $deliveryBoyNameMap[$db->id] = ['lang' => $langCode, 'name' => $db->name ?? ''];
                    }
                }
                $statusIds = array_unique(array_merge(
                    $order_items->pluck('active_status')->filter()->unique()->values()->toArray(),
                    $order && ($order->active_status ?? 0) ? [$order->active_status] : []
                ));
                $statusNameMap = [];
                $previousLocale = app()->getLocale();
                app()->setLocale($langCode);
                foreach ($statusIds as $sid) {
                    $sid = (int) $sid;
                    $translated = OrderStatusList::getTranslatedName($sid);
                    $fallback = ($order && ($order->active_status ?? 0) == $sid) ? ($order->status_name ?? '') : ($order_items->firstWhere('active_status', $sid)->status_name ?? '');
                    $statusNameMap[$sid] = ['lang' => $langCode, 'name' => $translated !== '' ? $translated : $fallback];
                }
                app()->setLocale($previousLocale);
                foreach ($order_items as $item) {
                    $item->store_name_translation = $storeNameMap[$item->store_id ?? 0] ?? ['lang' => $langCode, 'name' => $item->store_name ?? ''];
                    $statusEntry = $statusNameMap[$item->active_status ?? 0] ?? ['name' => $item->status_name ?? ''];
                    $item->order_status_name = $statusEntry['name'] ?? $item->status_name ?? '';
                }
                if ($order) {
                    $order->store_name_translation = $storeNameMap[$order->store_id ?? 0] ?? ['lang' => $langCode, 'name' => $order->store_name ?? ''];
                    $order->delivery_boy_name_translation = $deliveryBoyNameMap[$order->delivery_boy_id ?? 0] ?? ['lang' => $langCode, 'name' => $order->delivery_boy_name ?? ''];
                    $statusEntry = $statusNameMap[$order->active_status ?? 0] ?? ['name' => $order->status_name ?? ''];
                    $order->order_status_name = $statusEntry['name'] ?? $order->status_name ?? '';
                }
            } else {
                // All languages: Content-Language not passed (admin panel)
                $storeIds = array_unique(array_merge(
                    $order_items->pluck('store_id')->filter()->unique()->values()->toArray(),
                    $order && isset($order->store_id) ? [$order->store_id] : []
                ));
                $storeNameMap = [];
                if (!empty($storeIds)) {
                    foreach (Store::whereIn('id', $storeIds)->with('translations')->get(['id', 'name']) as $s) {
                        $nameByCode = [];
                        foreach ($s->getAllActiveLanguageTranslations() as $t) {
                            $code = $t['language_code'] ?? '';
                            if ($code !== '') {
                                $nameByCode[$code] = trim((string) ($t['name'] ?? ''));
                            }
                        }
                        $defaultName = $nameByCode[$defaultCode] ?? $s->getAttributeValue('name') ?? '';
                        $nameObj = (object) [];
                        foreach ($activeLangCodes as $code) {
                            $nameObj->{$code} = ($nameByCode[$code] ?? '') !== '' ? $nameByCode[$code] : $defaultName;
                        }
                        $storeNameMap[$s->id] = (array) $nameObj === [] ? (object) ['en' => $s->getAttributeValue('name') ?? ''] : $nameObj;
                    }
                }
                $deliveryBoyIds = $order && ($order->delivery_boy_id ?? 0) ? [$order->delivery_boy_id] : [];
                $deliveryBoyNameMap = [];
                if (!empty($deliveryBoyIds)) {
                    foreach (DeliveryBoy::whereIn('id', $deliveryBoyIds)->with('translations')->get(['id', 'name']) as $db) {
                        $nameByCode = [];
                        foreach ($db->getAllActiveLanguageTranslations() as $t) {
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
                        $deliveryBoyNameMap[$db->id] = (array) $nameObj === [] ? (object) ['en' => $db->getAttributeValue('name') ?? ''] : $nameObj;
                    }
                }
                $statusIds = array_unique(array_merge(
                    $order_items->pluck('active_status')->filter()->unique()->values()->toArray(),
                    $order && ($order->active_status ?? 0) ? [$order->active_status] : []
                ));
                $statusNameMap = [];
                $previousLocale = app()->getLocale();
                $previousLangCode = app()->has('lang_code') ? app('lang_code') : null;
                foreach ($statusIds as $sid) {
                    $sid = (int) $sid;
                    $key = OrderStatusList::getTranslationKey($sid);
                    $nameObj = (object) [];
                    if ($key !== '') {
                        foreach ($activeLangCodes as $code) {
                            app()->setLocale($code);
                            app()->instance('lang_code', $code);
                            $nameObj->{$code} = __($key);
                        }
                    }
                    $fallback = ($order && ($order->active_status ?? 0) == $sid) ? ($order->status_name ?? '') : ($order_items->firstWhere('active_status', $sid)->status_name ?? '');
                    $statusNameMap[$sid] = (array) $nameObj === [] ? (object) ['en' => $fallback] : $nameObj;
                }
                app()->setLocale($previousLocale);
                if ($previousLangCode !== null) {
                    app()->instance('lang_code', $previousLangCode);
                }
                foreach ($order_items as $item) {
                    $item->store_name_translation = $storeNameMap[$item->store_id ?? 0] ?? (object) ['en' => $item->store_name ?? ''];
                    $item->status_name_translation = $statusNameMap[$item->active_status ?? 0] ?? (object) ['en' => $item->status_name ?? ''];
                    $sid = (int) ($item->active_status ?? 0);
                    $item->order_status_name = OrderStatusList::getTranslatedName($sid) ?: ($item->status_name ?? '');
                }
                if ($order) {
                    $order->store_name_translation = $storeNameMap[$order->store_id ?? 0] ?? (object) ['en' => $order->store_name ?? ''];
                    $order->delivery_boy_name_translation = $deliveryBoyNameMap[$order->delivery_boy_id ?? 0] ?? (object) ['en' => $order->delivery_boy_name ?? ''];
                    $order->status_name_translation = $statusNameMap[$order->active_status ?? 0] ?? (object) ['en' => $order->status_name ?? ''];
                    $order->order_status_name = OrderStatusList::getTranslatedName((int) ($order->active_status ?? 0)) ?: ($order->status_name ?? '');
                }
            }
        }

        // Add tax_amount to price and discounted_price for each order item
        foreach ($order_items as $item) {
            $tax_amount = (float) ($item->tax_amount ?? 0);
            $item->price = (float) self::doubleNumber($item->price + $tax_amount);
            $item->discounted_price = (float) self::doubleNumber(
                ($item->discounted_price != 0 ? $item->discounted_price + $tax_amount : 0)
            );
        }

        // Display order placed time using admin date/time format (store settings)

        return array("order" => $order, "order_items" => $order_items);
    }

    public static function downloadOrderInvoice($order_id)
    {
        // Include cancelled/returned items so the invoice can show them (refunded) too.
        $data = CommonHelper::getOrderDetails($order_id, false);
        if (!$data["order"]) {
            return CommonHelper::responseError("Order Not found!");
        }
        CommonHelper::AdditionalChargesArray($data['order']);
        return self::invoicePdfFromData($data, $order_id);
    }

    /** Render the invoice PDF from prepared {order, order_items, ...} data. */
    public static function invoicePdfFromData(array $data, $fileId)
    {
        try {
            $invoice = view('invoice', $data)->render();
            
            // Configure mPDF with better settings for remote images and timeouts
            $mpdf = new Mpdf([
                'mode' => 'utf-8',
                'format' => 'A4',
                'margin_left' => 10,
                'margin_right' => 10,
                'margin_top' => 10,
                'margin_bottom' => 10,
                'margin_header' => 0,
                'margin_footer' => 0,
                // Allow fetching images from URLs (Cloudinary) but with timeout
                'curlAllowUnsafeSslRequests' => true,
                'curlTimeout' => 5, // 5 second timeout for remote images
            ]);
            
            // Disable image error throwing - skip problematic images instead of failing
            $mpdf->showImageErrors = false;
            
            // Use public_path() instead of asset() to get the file system path, not URL
            $cssPath = public_path('assets/css/custom/bootstrap/bootstrap.min.css');
            if (file_exists($cssPath)) {
                $stylesheet = file_get_contents($cssPath);
                $mpdf->WriteHTML($stylesheet, 1);
            } else {
                // Fallback: use inline minimal CSS if file doesn't exist
                $mpdf->WriteHTML('<style>body{font-family:sans-serif;}</style>', 1);
                \Log::warning('Invoice CSS file not found', ['path' => $cssPath]);
            }
            
            $mpdf->WriteHTML($invoice);

            $pdfContent = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);

            $filename = 'Invoice-No:#' . $fileId . '.pdf';
            return response($pdfContent, 200, [
                'Content-Type'                => 'application/pdf',
                'Content-Disposition'         => 'inline; filename="' . $filename . '"',
                'Access-Control-Allow-Origin' => '*',
            ]);
        } catch (\Exception $e) {
            \Log::error('Invoice PDF generation failed', [
                'order_id' => $fileId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'status' => 0,
                'message' => 'Failed to generate invoice: ' . $e->getMessage()
            ], 500);
        }
    }

    public static function getFirebaseKeys()
    {
        $firebase_array = array(
            "firebase_apiKey" => "",
            "authDomain" => "",
            "databaseURL" => "",
            "projectId" => "",
            "storageBucket" => "",
            "messagingSenderId" => "",
            "appId" => "",
            "measurementId" => "",
            "firebase_vapid_key" => "",
            "jsonFile" => ""
        );
        $variables = array_keys($firebase_array);
        return Setting::whereIn('variable', $variables)->get();
    }

    public static function sendMail($to, $subject, $data)
    {
        $to = is_string($to) ? trim($to) : $to;
        if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Log::warning('[Mail] skipped — invalid or empty recipient', ['to' => $to, 'subject' => $subject]);
            return;
        }

        $fromMail = Setting::get_value('smtp_from_mail');
        if (empty($fromMail) || !filter_var($fromMail, FILTER_VALIDATE_EMAIL)) {
            Log::warning('[Mail] skipped — smtp_from_mail not configured', ['subject' => $subject]);
            return;
        }

        $mailer = Setting::get_value('mailer') ?: 'smtp';
        $config = [
            'driver' => $mailer,
            'host' => Setting::get_value('smtp_host'),
            'username' => Setting::get_value('smtp_from_mail'),
            'password' => Setting::get_value('smtp_email_password'),
            'port' => (int) Setting::get_value('smtp_port'),
            'encryption' => Setting::get_value('smtp_encryption_type'),
            'from'       => [
                'address' => Setting::get_value('smtp_from_mail'),
                'name'    => Setting::get_value('app_name'),
            ]
        ];

        Config::set('mail', $config);
        Mail::purge($mailer);

        $app_name = Setting::get_value('app_name');
        $mailData = array(
            'to' => $to,
            'subject' => $subject,
            'name' => $data['name'] ?? "",
            'app_name' => $app_name,
            'support_email' => Setting::get_value('smtp_from_mail'),
            // Optional file attached to the mail (e.g. promotional image/PDF).
            'attachment' => (is_array($data) && !empty($data['attachment']) && is_file($data['attachment']))
                ? $data['attachment'] : null,
        );
        if (!is_array($data)) {
            $data = [];
        }

        $data['app_name'] = $app_name;

        Mail::send('mail', $data, function ($message) use ($mailData) {
            $message->to($mailData['to'], $mailData['name'])->subject($mailData['subject'])->from($mailData['support_email'], $mailData["app_name"]);
            if ($mailData['attachment']) {
                $message->attach($mailData['attachment']);
            }
        });
    }
    /**
     * Get subject (title) and body (message) for an email template type and language.
     * Requested-language translation → default-language translation → base template row.
     */
    public static function getEmailTemplateContent(string $type, int $languageId): array
    {
        $template = EmailTemplate::where('type', $type)->first();
        if (!$template) {
            return ['title' => '', 'message' => ''];
        }

        $trans = EmailTemplateTranslation::where('email_template_id', $template->id)
            ->where('language_id', $languageId)
            ->first();
        if ($trans) {
            return ['title' => $trans->title ?? '', 'message' => $trans->message ?? ''];
        }

        $defaultLangId = self::getDefaultLanguageId();
        if ($defaultLangId !== $languageId) {
            $trans = EmailTemplateTranslation::where('email_template_id', $template->id)
                ->where('language_id', $defaultLangId)
                ->first();
            if ($trans) {
                return ['title' => $trans->title ?? '', 'message' => $trans->message ?? ''];
            }
        }

        return ['title' => $template->title ?? '', 'message' => $template->message ?? ''];
    }

    /**
     * Send a template-driven email in the recipient's language (users.language_id).
     * Global placeholders app_name / support_email / support_number are merged in.
     */
    public static function sendMailByTemplate($to, string $templateType, array $placeholders = [], ?int $languageId = null): void
    {
        $languageId = $languageId ?: self::getDefaultLanguageId();

        $content = self::getEmailTemplateContent($templateType, (int) $languageId);
        if ($content['title'] === '' && $content['message'] === '') {
            Log::warning("[Mail] template '{$templateType}' not found — email skipped.");
            return;
        }

        $placeholders = array_merge([
            'app_name'       => Setting::get_value('app_name') ?? '',
            'support_email'  => Setting::get_value('support_email') ?: (Setting::get_value('smtp_from_mail') ?? ''),
            'support_number' => Setting::get_value('support_number') ?? '',
        ], $placeholders);

        $rawHtml = [];
        foreach ($placeholders as $k => $v) {
            if (str_ends_with($k, '_html')) {
                $rawHtml[$k] = (string) $v;
                unset($placeholders[$k]);
            }
        }

        $subject = trim(html_entity_decode(strip_tags(self::replacePlaceholders($content['title'], $placeholders))));
        $message = self::replacePlaceholders($content['message'], $placeholders);

        // Rich templates (built in the TinyMCE editor) are already HTML — render as-is.
        // Plain-text templates keep the legacy escape + nl2br so line breaks survive.
        $isHtml = $message !== strip_tags($message);
        $contentHtml = $isHtml ? $message : nl2br(e($message));
        foreach ($rawHtml as $k => $v) {
            $contentHtml = str_replace('{{' . $k . '}}', $v, $contentHtml);
        }

        $data = [
            'type'         => 'template',
            'email_title'  => $subject,
            'content_html' => $contentHtml,
            'name'         => $placeholders['customer_name'] ?? ($placeholders['delivery_boy_name'] ?? ''),
        ];

        self::sendMail($to, $subject, $data);
    }

    /**
     * Order/return status name translated into the given language (default lang when null).
     */
    public static function translatedStatusNameFor(?int $languageId, ?int $orderStatusId = null, ?int $returnStatusId = null, bool $customerFacing = false): string
    {
        $languageId = $languageId ?: self::getDefaultLanguageId();
        $langCode = app(LanguageService::class)->getLanguageCode((int) $languageId);
        $previousLocale = App::getLocale();
        if ($langCode) {
            App::setLocale($langCode);
        }
        // Customer-facing shows "Order Placed" for the "Received" status.
        $name = $orderStatusId !== null
            ? ($customerFacing
                ? OrderStatusList::getCustomerTranslatedName((int) $orderStatusId)
                : OrderStatusList::getTranslatedName((int) $orderStatusId))
            : ReturnStatusList::getTranslatedName((int) $returnStatusId);
        App::setLocale($previousLocale);
        return $name;
    }


    public static function sendReturnRequestNotification($returnRequest)
    {
        $app_name = Setting::get_value('app_name');

        $status_name = ReturnStatusList::getStatusName($returnRequest->status);
        $orderNumber = Order::where('id', $returnRequest->order_id)->value('order_number') ?: $returnRequest->order_id;

        $placeholdersCustomer = [
            'return_request_id' => $returnRequest->id,
            'order_id'         => $orderNumber,
            'status_name'      => $status_name,
            'app_name'         => $app_name,
        ];
        $placeholdersSeller = [
            'return_request_id' => $returnRequest->id,
            'order_id'         => $orderNumber,
            'status_name'      => $status_name,
        ];

        $returnEvent = NotificationService::returnStatusEventKey((int) $returnRequest->status);

        $returnPushOpts = ['returnStatusId' => $returnRequest->status, 'payloadType' => 'return_request', 'payloadId' => $returnRequest->id];

        // Customer
        if ($returnEvent && !empty($returnRequest->user_id)) {
            NotificationService::pushIfAllowed('customer', (int) $returnRequest->user_id, $returnEvent,
                UserToken::where('user_id', $returnRequest->user_id)->where('type', 'customer')->get(),
                $placeholdersCustomer, $returnPushOpts);
        }

        // Delivery boy (when assigned / status changes) — all enabled channels.
        if ($returnEvent && !empty($returnRequest->delivery_boy_id)) {
            $deliveryBoy = DeliveryBoy::where('id', $returnRequest->delivery_boy_id)->first();
            if ($deliveryBoy && !empty($deliveryBoy->admin_id)) {
                $dbPh = array_merge($placeholdersSeller, ['app_name' => $app_name, 'delivery_boy_name' => $deliveryBoy->name ?? '']);
                NotificationService::dispatch('delivery_boy', (int) $deliveryBoy->admin_id, $returnEvent, [
                    'email'       => optional(Admin::find($deliveryBoy->admin_id))->email,
                    'phone'       => $deliveryBoy->mobile ? trim(($deliveryBoy->country_code ?? '') . $deliveryBoy->mobile) : null,
                    'tokens'      => AdminToken::where('user_id', $deliveryBoy->admin_id)->where('type', 'Delivery Boy')->get(),
                    'language_id' => $deliveryBoy->language_id ?? null,
                ], $dbPh, $returnPushOpts);
            }
        }

        self::sendReturnRequestSms($returnRequest);
        self::sendReturnRequestMail($returnRequest);
    }

    /**
     * Store panel notifications for seller and admins when a customer submits a return request.
     */
    public static function sendReturnRequestPanelNotifications($returnRequest)
    {
        $orderItem = OrderItem::find($returnRequest->order_item_id);
        if (!$orderItem) {
            return;
        }

        $admin_ids_to_notify = [];

        // Super admins (role_id = 1)
        $super_admin_ids = Admin::where('role_id', 1)->pluck('id')->toArray();
        $admin_ids_to_notify = array_merge($admin_ids_to_notify, $super_admin_ids);

        // The store users owning the returned item's store.
        if (!empty($orderItem->store_id)) {
            $store_admin_ids = Admin::where('store_id', $orderItem->store_id)
                ->where('status', 1)
                ->pluck('id')->toArray();
            $admin_ids_to_notify = array_merge($admin_ids_to_notify, $store_admin_ids);
        }

        $admin_ids_to_notify = array_unique($admin_ids_to_notify);

        foreach ($admin_ids_to_notify as $admin_id) {
            try {
                // Respect each recipient's notification preference (default ON).
                if (!NotificationService::allowed('admin', (int) $admin_id, 'return_request_new', 'push')) {
                    continue;
                }
                $admin = Admin::find($admin_id);
                if ($admin) {
                    $admin->notify(new OrderNotification($orderItem->id, 'return_request_new'));
                }
            } catch (\Exception $e) {
                Log::error("Error sending return request panel notification to admin {$admin_id}: " . $e->getMessage());
            }
        }
    }

    /**
     * Return-request status SMS. No admin toggle (there is no mail_settings row for
     * return statuses) — sent whenever the customer has a mobile, in their language.
     */
    public static function sendReturnRequestSms($returnRequest)
    {
        try {
            $user = User::find($returnRequest->user_id);
            if (!$user || empty($user->mobile)) {
                return;
            }

            $key = ReturnStatusList::getTranslationKey((int) $returnRequest->status);
            if ($key === '') {
                return;
            }
            $type = 'return_status_' . $key . '_customer';

            // Honour the customer's return-status SMS preference + admin switch.
            $returnEvent = NotificationService::returnStatusEventKey((int) $returnRequest->status);
            if ($returnEvent && !NotificationService::allowed('customer', (int) $user->id, $returnEvent, 'sms')) {
                return;
            }

            $phone = trim(($user->country_code ?? '') . $user->mobile);
            $placeholders = [
                'customer_name'     => $user->name ?? '',
                'return_request_id' => $returnRequest->id,
                'order_id'          => Order::where('id', $returnRequest->order_id)->value('order_number') ?: $returnRequest->order_id,
            ];

            SmsHelper::sendByTemplate($phone, $type, $placeholders, $user->language_id);
        } catch (\Exception $e) {
            Log::error("Error sending return request SMS: " . $e->getMessage());
        }
    }

    public static function sendReturnRequestMail($returnRequest)
    {
        try {
            $customer = User::find($returnRequest->user_id);
            if (!$customer || !$customer->email) {
                Log::error("Customer email not found for return request #{$returnRequest->id}");
                return;
            }

            $returnEvent = NotificationService::returnStatusEventKey((int) $returnRequest->status);
            if ($returnEvent && !NotificationService::allowed('customer', (int) $customer->id, $returnEvent, 'mail')) {
                return;
            }

            $langId = $customer->language_id ? (int) $customer->language_id : null;
            $cReturnMail = NotificationService::templateType($returnEvent, 'customer');
            self::sendMailByTemplate($customer->email, $cReturnMail, [
                'customer_name'     => $customer->name ?? '',
                'return_request_id' => $returnRequest->id,
                'order_id'          => Order::where('id', $returnRequest->order_id)->value('order_number') ?: $returnRequest->order_id,
                'status_name'       => self::translatedStatusNameFor($langId, null, (int) $returnRequest->status),
            ], $langId);
        } catch (\Exception $e) {
            Log::error("Error sending return request mail: " . $e->getMessage());
        }
    }

    public static function sendMailOrderStatus($order, $assign = false, $type = "")
    {
        if ($assign == true) {
            if (isset($order->delivery_boy_id) && $order->delivery_boy_id != 0 && $order->delivery_boy_id != "") {
                $deliveryBoy = DeliveryBoy::select("delivery_boys.*", "admins.email", "admins.role_id")
                    ->Join('admins', 'delivery_boys.admin_id', 'admins.id')
                    ->where('delivery_boys.id', $order->delivery_boy_id)->first();
                if ($deliveryBoy && !empty($deliveryBoy->email)) {
                    self::sendMailByTemplate($deliveryBoy->email, 'assign_order_delivery_boy', [
                        'delivery_boy_name' => $deliveryBoy->name ?? '',
                        'order_id'          => $order->order_number ?? $order->id,
                        'redirect_url'      => url('/delivery_boy/orders/view/' . $order->id),
                    ]);
                }
            }
            return;
        }

        // When type is order_item_status_update, $order is OrderItem - resolve parent Order.
        $order_item = null;
        $mail_order = $order;
        if ($type == 'order_item_status_update' && isset($order->order_id)) {
            $order_item = $order;
            $mail_order = Order::find($order->order_id);
            if (!$mail_order) {
                return;
            }
        }

        $status_id = (int) $order->active_status;
        $order_id = $order_item ? $order_item->order_id : $order->id;
        $order_id = $mail_order->order_number ?? $order_id;
        $currency = $mail_order->currency ?? (Setting::get_value('currency') ?: '');

        // Customer — in their own language (users.language_id).
        if (!empty($mail_order->user_id) && self::checkOrderMailSendable($mail_order->user_id, $status_id, 0)) {
            $customer = User::find($mail_order->user_id);
            if ($customer && !empty($customer->email)) {
                $langId = $customer->language_id ? (int) $customer->language_id : null;
                $placeholders = [
                    'customer_name' => $customer->name ?? '',
                    'order_id'      => $order_id,
                    'status_name'   => self::translatedStatusNameFor($langId, $status_id, null, true),
                    'created_at'    => $mail_order->created_at,
                    'currency'      => $currency,
                    'final_total'   => $order_item ? $order_item->final_total : $mail_order->final_total,
                ];
                if ($order_item) {
                    $placeholders['order_item_id'] = $order_item->id;
                    $placeholders['product_name']  = $order_item->product_name ?? '';
                    $placeholders['quantity']      = $order_item->quantity ?? 1;
                    $template = $status_id === (int) OrderStatusList::$cancelled
                        ? 'order_item_cancelled_customer'
                        : 'order_item_status_customer';
                } else {
                    // Per-status customer mail template.
                    $template = NotificationService::templateType(
                        NotificationService::orderStatusEventKey($status_id),
                        'customer',
                        'mail'
                    );

                    $placeholders['order_items_html'] = self::orderItemsTableHtml($mail_order, $currency);
                }
                self::sendMailByTemplate($customer->email, $template, $placeholders, $langId);
            }
        }

        // Delivery boy — item's boy first (ecommerce), else the order's (quick).
        $delivery_boy_id = $order_item
            ? ($order_item->delivery_boy_id ?: ($mail_order->delivery_boy_id ?? 0))
            : ($order->delivery_boy_id ?? 0);
        if (!empty($delivery_boy_id)) {
            $deliveryBoy = DeliveryBoy::select("delivery_boys.*", "admins.email", "admins.role_id")
                ->Join('admins', 'delivery_boys.admin_id', 'admins.id')
                ->where('delivery_boys.id', $delivery_boy_id)->first();
            if ($deliveryBoy && !empty($deliveryBoy->email)
                && self::checkOrderMailSendable($deliveryBoy->admin_id, $status_id, 1, 'mail', 'delivery_boy')) {
                $dbMailType = NotificationService::templateType(
                    NotificationService::orderStatusEventKey($status_id), 'delivery_boy', 'mail');
                self::sendMailByTemplate($deliveryBoy->email, $dbMailType, [
                    'delivery_boy_name' => $deliveryBoy->name ?? '',
                    'order_id'          => $order_id,
                    'status_name'       => self::translatedStatusNameFor(null, $status_id),
                    'product_name'      => $order_item->product_name ?? '',
                ]);
            }
        }

        // Admins (super admin + seller) — default language, gated per admin.
        $adminStatusName = self::translatedStatusNameFor(null, $status_id);
        $adminProductName = $order_item
            ? ($order_item->product_name ?? '')
            : ($mail_order->items[0]->product_name ?? '');
        $adminMailType = NotificationService::templateType(
            NotificationService::orderStatusEventKey($status_id), 'admin', 'mail');
        foreach (Admin::whereIn('role_id', [1, 2])->get() as $admin) {
            if (!empty($admin->email) && self::checkOrderMailSendable($admin->id, $status_id, 1)) {
                self::sendMailByTemplate($admin->email, $adminMailType, [
                    'admin_name'   => $admin->name ?? '',
                    'order_id'     => $order_id,
                    'status_name'  => $adminStatusName,
                    'product_name' => $adminProductName,
                ]);
            }
        }
    }

    /**
     * Used for order items detail in mail
     */
    private static function orderItemsTableHtml($order, string $currency): string
    {
        // Join the product for its image (order_items don't store one).
        $items = OrderItem::where('order_items.order_id', $order->id)
            ->leftJoin('product_variants', 'product_variants.id', '=', 'order_items.product_variant_id')
            ->leftJoin('products', 'products.id', '=', 'product_variants.product_id')
            ->get([
                'order_items.product_name',
                'order_items.quantity',
                'order_items.price',
                'order_items.discounted_price',
                'order_items.sub_total',
                'products.image as product_image',
            ]);
        if ($items->isEmpty()) {
            return '';
        }

        $money = fn ($v) => e($currency) . number_format((float) $v, 2);

        $cell = 'padding:8px;border-bottom:1px solid #eee;';
        $rows = '';
        foreach ($items as $item) {
            $unit = (float) $item->discounted_price > 0 ? (float) $item->discounted_price : (float) $item->price;
            $imgCell = $item->product_image
                ? '<img src="' . e(asset('storage/' . $item->product_image)) . '" alt="" width="48" height="48" style="width:48px;height:48px;object-fit:cover;border-radius:4px;display:block;" />'
                : '';
            $rows .= '<tr>'
                . '<td style="' . $cell . 'width:56px;">' . $imgCell . '</td>'
                . '<td style="' . $cell . '">' . e($item->product_name ?? '') . '</td>'
                . '<td style="' . $cell . 'text-align:center;">' . (int) $item->quantity . '</td>'
                . '<td style="' . $cell . 'text-align:right;">' . $money($unit) . '</td>'
                . '<td style="' . $cell . 'text-align:right;">' . $money($item->sub_total) . '</td>'
                . '</tr>';
        }

        $th = 'padding:8px;';
        $table = '<table style="width:100%;border-collapse:collapse;font-size:14px;margin:12px 0;">'
            . '<thead><tr style="background:#f5f5f5;">'
            . '<th style="' . $th . '"></th>'
            . '<th style="' . $th . 'text-align:left;">' . e(__('product')) . '</th>'
            . '<th style="' . $th . 'text-align:center;">' . e(__('quantity')) . '</th>'
            . '<th style="' . $th . 'text-align:right;">' . e(__('price')) . '</th>'
            . '<th style="' . $th . 'text-align:right;">' . e(__('total')) . '</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';

        // Price breakdown — same rows/order as the invoice (uses remaining_* so a partly
        // cancelled/refunded order still totals correctly).
        $sRow = fn ($label, $value, $bold = false) =>
            '<tr>'
            . '<td style="padding:6px 8px;' . ($bold ? 'font-weight:bold;border-top:2px solid #ddd;' : '') . '">' . e($label) . '</td>'
            . '<td style="padding:6px 8px;text-align:right;' . ($bold ? 'font-weight:bold;border-top:2px solid #ddd;' : '') . '">' . $value . '</td>'
            . '</tr>';

        $summary = $sRow(__('order_total'), $money($order->remaining_total));
        if ((float) $order->delivery_charge > 0) {
            $summary .= $sRow(__('delivery_charge'), $money($order->delivery_charge));
        }
        foreach ((array) $order->additional_charges as $charge) {
            if ((float) ($charge['amount'] ?? 0) > 0) {
                $summary .= $sRow($charge['title'] ?? $charge['name'] ?? __('additional_charge'), $money($charge['amount']));
            }
        }
        foreach ((array) $order->surge_charges as $charge) {
            if ((float) ($charge['amount'] ?? 0) > 0) {
                $summary .= $sRow($charge['title'] ?? $charge['name'] ?? __('surge_charge'), $money($charge['amount']));
            }
        }
        if ((float) $order->promo_discount > 0) {
            $label = __('promo_code') . ($order->promo_code ? ' (' . $order->promo_code . ')' : '');
            $summary .= $sRow($label, '- ' . $money($order->promo_discount));
        }
        if ((float) $order->wallet_balance > 0) {
            $summary .= $sRow(__('wallet_used'), '- ' . $money($order->wallet_balance));
        }
        $summary .= $sRow(__('final_total'), $money($order->remaining_final), true);
        if (!empty($order->payment_method)) {
            $summary .= $sRow(__('payment_method'), e(strtoupper($order->payment_method)));
        }

        $summaryTable = '<table style="width:100%;max-width:340px;margin-left:auto;border-collapse:collapse;font-size:14px;">'
            . '<tbody>' . $summary . '</tbody></table>';

        return $table . $summaryTable;
    }

    /**
     * Order-status mail/push gate, now backed by the notification catalog
     * (admin master switch -> recipient preference, default ON).
     *
     * @param string $type      "mail" or anything else (= push)
     * @param string|null $audience  override; else derived from $use_type
     *                                (0 => customer, 1 => admin). Pass 'delivery_boy'
     *                                explicitly for a delivery-boy recipient.
     */
    public static function checkOrderMailSendable($user_id, $status_id, $use_type, $type = "mail", $audience = null)
    {
        $channel = ($type === 'mail') ? 'mail' : 'push';
        $eventKey = NotificationService::orderStatusEventKey((int) $status_id);
        if (!$eventKey) {
            return false;
        }
        if ($audience === null) {
            $audience = ((int) $use_type === 0) ? 'customer' : 'admin';
        }
        return NotificationService::allowed(
            $audience,
            $user_id ? (int) $user_id : null,
            $eventKey,
            $channel
        );
    }

    public static function sendNotificationOrderStatus($order, $type = '')
    {
        $app_name = Setting::get_value('app_name');
        $currency = Setting::get_value('currency') ?? '$';

        // When this is an order-item status update (e.g. item cancelled), first param is OrderItem.
        // Resolve parent order so we have correct order_id, user_id, delivery_boy_id for notifications.
        $orderItem = null;
        if ($type == 'order_item_status_update' && isset($order->order_id)) {
            $orderItem = $order;
            $order = Order::find($order->order_id);
            if (!$order) {

                return;
            }
        }

        $status_id = $orderItem ? $orderItem->active_status : $order->active_status;
        $orderStatusList = OrderStatusList::where('id', $status_id)->first();
        $status_name = $orderStatusList ? $orderStatusList->status : '';
        // Customers see "Order Placed" instead of "Received"; admin/delivery boy keep "Received".
        $customer_status_name = ((int) $status_id === OrderStatusList::$received)
            ? __('order_placed')
            : $status_name;
        $order_id = $order->id;
        $orderNumber = $order->order_number ?? $order_id;

        $orderItemId = ($orderItem && $order->channel === 'ecommerce') ? $orderItem->id : null;

        $itemImage = '';
        if ($orderItem && $order->channel === 'ecommerce' && (int) $status_id === OrderStatusList::$delivered) {
            $productImage = DB::table('product_variants')
                ->join('products', 'products.id', '=', 'product_variants.product_id')
                ->where('product_variants.id', $orderItem->product_variant_id)
                ->value('products.image');
            if ($productImage) {
                $itemImage = asset('storage/' . $productImage);
            }
        }

        // Notify customer
        $user_id = $order->user_id;
        if (isset($user_id) && $user_id != "") {
            if (self::checkOrderMailSendable($user_id, $status_id, 0, "Notification")) {
                $userTokens = UserToken::where('user_id', $user_id)->where('type', 'customer')->get();

                $placeholders = [
                    'order_id'     => $orderNumber,
                    'status_name'  => $customer_status_name,
                    'created_at'   => $order->created_at,
                    'app_name'     => $app_name,
                    'currency'     => $currency,
                    'final_total'  => $order->final_total,
                ];

                if ($orderItem) {
                    $placeholders['order_item_id'] = $orderItem->id;
                    $placeholders['product_name']  = $orderItem->product_name ?? '';
                    $placeholders['quantity']      = $orderItem->quantity ?? 1;
                    $placeholders['final_total']   = $orderItem->final_total;

                    $template = ((int) $status_id === OrderStatusList::$cancelled)
                        ? 'order_item_cancelled_customer'
                        : 'order_item_status_customer';
                } else {
                    // Per-status customer push template.
                    $template = NotificationService::templateType(NotificationService::orderStatusEventKey((int) $status_id), 'customer', 'push');
                }

                self::sendNotificationByTemplate($userTokens, $template, $placeholders, '', 0, $itemImage, $status_id, null, 'order', $order_id, null, $orderItemId, true);
            }
        }

        // Notify delivery boy using order's delivery_boy_id (from orders table)
        if (isset($order->delivery_boy_id) && $order->delivery_boy_id != 0 && $order->delivery_boy_id != "") {
            $deliveryBoy = DeliveryBoy::select("delivery_boys.*", "admins.email", "admins.role_id")
                ->Join('admins', 'delivery_boys.admin_id', 'admins.id')
                ->where('delivery_boys.id', $order->delivery_boy_id)
                ->first();
            if ($deliveryBoy) {
                $dbEvent = NotificationService::orderStatusEventKey((int) $status_id);
                if ($dbEvent) {
                    $dbId = (int) $deliveryBoy->admin_id;
                    $placeholders = ['order_id' => $orderNumber, 'status_name' => $status_name, 'delivery_boy_name' => $deliveryBoy->name ?? ''];
                    NotificationService::pushIfAllowed('delivery_boy', $dbId, $dbEvent,
                        AdminToken::where('user_id', $dbId)->where('type', 'Delivery Boy')->get(),
                        $placeholders,
                        ['orderStatusId' => $status_id, 'payloadType' => 'order', 'payloadId' => $order_id, 'orderItemId' => $orderItemId]);
                    NotificationService::smsIfAllowed('delivery_boy', $dbId, $dbEvent,
                        !empty($deliveryBoy->mobile) ? trim(($deliveryBoy->country_code ?? '') . $deliveryBoy->mobile) : null,
                        $placeholders, $deliveryBoy->language_id ?? null);
                }
            }
        }

        // Admin push (default off; admin master switch only).
        $adminEvent = NotificationService::orderStatusEventKey((int) $status_id);
        if ($adminEvent) {
            NotificationService::pushIfAllowed('admin', null, $adminEvent,
                AdminToken::whereIn('user_id', Admin::whereIn('role_id', [1, 2])->pluck('id'))->whereIn('type', ['Super Admin', 'Admin'])->get(),
                ['order_id' => $orderNumber, 'status_name' => $status_name, 'app_name' => $app_name],
                ['orderStatusId' => $status_id, 'payloadType' => 'order', 'payloadId' => $order_id, 'orderItemId' => $orderItemId]);
        }
    }

    public static function sendNotificationOrderAssignDeliveryBoy($order)
    {
        if (isset($order->delivery_boy_id) && $order->delivery_boy_id != 0 && $order->delivery_boy_id != "") {
            $deliveryBoy = DeliveryBoy::find($order->delivery_boy_id);
            if (!$deliveryBoy) {
                return;
            }
            $deliveryBoyUserId = (int) $deliveryBoy->admin_id;
            $orderNumber = $order->order_number ?? $order->id;
            $appName = Setting::get_value('app_name');
            $pushOpts = ['type' => 'assign_order', 'payloadType' => 'order', 'payloadId' => $order->id];

            if ($deliveryBoyUserId) {
                NotificationService::dispatch('delivery_boy', $deliveryBoyUserId, 'assign_order', [
                    'email'       => optional(Admin::find($deliveryBoyUserId))->email,
                    'phone'       => $deliveryBoy->mobile ? trim(($deliveryBoy->country_code ?? '') . $deliveryBoy->mobile) : null,
                    'tokens'      => AdminToken::where('user_id', $deliveryBoyUserId)->where('type', 'Delivery Boy')->get(),
                    'language_id' => $deliveryBoy->language_id ?? null,
                ], ['app_name' => $appName, 'order_id' => $orderNumber, 'delivery_boy_name' => $deliveryBoy->name ?? '', 'redirect_url' => url('/delivery_boy/orders/view/' . $order->id)], $pushOpts);
            }
            if (!empty($order->user_id)) {
                $customer = User::find($order->user_id);
                NotificationService::dispatch('customer', (int) $order->user_id, 'assign_order', [
                    'email'       => $customer->email ?? null,
                    'phone'       => ($customer && $customer->mobile) ? trim(($customer->country_code ?? '') . $customer->mobile) : null,
                    'tokens'      => UserToken::where('user_id', $order->user_id)->where('type', 'customer')->get(),
                    'language_id' => $customer->language_id ?? null,
                ], ['app_name' => $appName, 'customer_name' => $customer->name ?? '', 'order_id' => $orderNumber, 'delivery_boy_name' => $deliveryBoy->name ?? 'Delivery Partner'], $pushOpts);
            }
        }
    }

    public static function getPushObject($request, $image = "")
    {

        if ($request->hasFile('image') && $image != "") {
            $image_url = Storage::url($image);
            $image_url = asset($image_url);
            $push = new PushHelpers(
                $request->title,
                $request->message,
                $image_url,
                $request->type,
                $request->type_id,
                $request->type_link ?? ""
            );
        } else {
            $push = new PushHelpers(
                $request->title,
                $request->message,
                null,
                $request->type,
                $request->type_id,
                $request->type_link ?? ""
            );
        }

        //getting the push from push object
        $pushNotification = $push->getPush();

        return $pushNotification;
    }

    public static function sendNotification($userTokens, $title, $message, $type = '', $type_id = 0, $image = '', ?string $payloadType = null, $payloadId = null, $payloadSlug = null, $orderItemId = null)
    {
        $data = array();

        $logo = Setting::get_value('logo');
        if ($logo) {
            $logo = url('/storage') . "/" . $logo;
        } else {
            $logo = asset('images/favicon.png');
        }

        if ($payloadType !== null && $payloadType !== '') {
            $routingType = (string) $payloadType;
            $routingId = $payloadId !== null ? (string) $payloadId : '';
        } else {
            $routingType = (string) $type;
            $routingId = (string) ($type_id ?? '');
        }
        $soundType = in_array((string) $type, ['new_order', 'assign_order'], true) ? (string) $type : '';

        // FCM data payload requires ALL values to be strings.
        // `type` + `id` are the deep-link pair the apps route on:
        //   order | order_item -> order id      return_request -> return request id
        //   wallet             -> wallet txn    withdrawal_request -> request id
        //   chat               -> conversation id
        $fcmMsg = [
            'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
            'title' =>  (string) $title,
            'message' =>  (string) $message,
            'body' => (string) $message,
            'type' =>  $routingType,
            'id' => $routingId,
            'sound_type' => $soundType,
            'icon' => (string) $logo,
            'image' => (string) $image,
            'type_slug' => $payloadSlug,
        ];

        if ($orderItemId !== null && $orderItemId !== '') {
            $fcmMsg['order_item_id'] = (string) $orderItemId;
        }

        $notification = [
            'title' => $title,
            'body' =>  $message,
        ];

        if (isset($userTokens) && count($userTokens) > 0) {
            $userTokens = array_unique($userTokens);

            $result = null;
            foreach ($userTokens as $platform => $deviceToken) {
                try {

                    $result = FirebaseHelper::send($platform, $deviceToken, $fcmMsg);
                } catch (\Exception $e) {
                    Log::error("Error sending notification to device token: $deviceToken - " . $e->getMessage());
                }
            }
            return $result;
        } else {
            Log::warning('[sendNotification] No tokens to send to');
        }
        return null;
    }

    public static function getDefaultLanguageId(): int
    {
        $id = (int) Language::where('system_type', 4)->where('is_default', 1)->value('id');
        return $id ?: 1;
    }

    public static function randomActiveProductNames(int $limit = 10): array
    {
        $langId = LanguageService::getCurrentId() ?: self::getDefaultLanguageId();
        $defaultId = self::getDefaultLanguageId();

        return DB::table('products as p')
            ->join('product_variants as pv', 'pv.id', '=', DB::raw(
                '(SELECT MIN(pv2.id) FROM product_variants pv2 WHERE pv2.product_id = p.id AND pv2.deleted_at IS NULL)'
            ))
            ->leftJoin('product_variant_translations as t', function ($j) use ($langId) {
                $j->on('t.product_variant_id', '=', 'pv.id')->where('t.language_id', $langId);
            })
            ->leftJoin('product_variant_translations as td', function ($j) use ($defaultId) {
                $j->on('td.product_variant_id', '=', 'pv.id')->where('td.language_id', $defaultId);
            })
            ->where('p.status', 1)
            ->whereNull('p.deleted_at')
            ->select(DB::raw('COALESCE(NULLIF(t.name, ""), NULLIF(td.name, ""), pv.name) as pname'))
            ->havingRaw('pname IS NOT NULL AND pname != ""')
            ->inRandomOrder()
            ->limit($limit)
            ->pluck('pname')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Forward-delivery chat visibility: a delivery boy is assigned and the order/item is still
     * live (not delivered / cancelled / returned).
     */
    public static function isDeliveryBoyChatVisibleForward(?int $deliveryBoyId, int $activeStatus): bool
    {
        $hidden = [OrderStatusList::$delivered, OrderStatusList::$cancelled, OrderStatusList::$returned];
        return !empty($deliveryBoyId) && !in_array($activeStatus, $hidden, true);
    }

    /**
     * Return-pickup chat visibility: a delivery boy is assigned to the return request and it
     * isn't rejected / refund-completed.
     */
    public static function isDeliveryBoyChatVisibleForReturn(?int $deliveryBoyId, int $returnStatus): bool
    {
        return !empty($deliveryBoyId)
            && !in_array($returnStatus, [ReturnStatusList::$rRejected, ReturnStatusList::$rRefundCompleted], true);
    }

    /**
     * Ecommerce order-item chat visibility = forward delivery OR (once returned) the return
     * pickup is still active. Quick orders are never returnable → use the forward check only.
     */
    public static function isDeliveryBoyChatVisibleForOrderItem(int $orderItemId, ?int $deliveryBoyId, int $activeStatus): bool
    {
        if (self::isDeliveryBoyChatVisibleForward($deliveryBoyId, $activeStatus)) {
            return true;
        }
        if ($activeStatus === OrderStatusList::$returned) {
            $rr = ReturnRequest::where('order_item_id', $orderItemId)->first(['delivery_boy_id', 'status']);
            return $rr && self::isDeliveryBoyChatVisibleForReturn((int) $rr->delivery_boy_id, (int) $rr->status);
        }
        return false;
    }

    /**
     * Get title and message for a notification template type and language.
     * Uses notification_template_translations; fallback to default language then to base notification_templates.
     */
    public static function getNotificationTemplateContent(string $type, int $languageId): array
    {

        $template = NotificationTemplate::where('type', $type)->first();
        if (!$template) {
            return ['title' => '', 'message' => ''];
        }

        // Try to get translation for requested language
        $trans = NotificationTemplateTranslation::where('notification_template_id', $template->id)
            ->where('language_id', $languageId)
            ->first();
        if ($trans) {
            return ['title' => $trans->title ?? '', 'message' => $trans->message ?? ''];
        }

        // Fallback to default language
        $defaultLangId = self::getDefaultLanguageId();
        if ($defaultLangId !== $languageId) {
            $trans = NotificationTemplateTranslation::where('notification_template_id', $template->id)
                ->where('language_id', $defaultLangId)
                ->first();
            if ($trans) {
                return ['title' => $trans->title ?? '', 'message' => $trans->message ?? ''];
            }
        }

        // Fallback to base template
        return ['title' => $template->title ?? '', 'message' => $template->message ?? ''];
    }

    public static function replacePlaceholders(string $str, array $placeholders): string
    {
        foreach ($placeholders as $key => $value) {
            $str = str_replace('{{' . $key . '}}', (string) $value, $str);
        }
        return $str;
    }

    /**
     * Send FCM to a collection of tokens (UserToken or AdminToken) by language.
     * Groups tokens by language_id (null => current default language), resolves template per language, sends to every token.
     * Sends to all devices for each user/admin, not just one per platform.
     * When $orderStatusId or $returnStatusId is set, status_name placeholder is translated per language.
     */
    public static function sendNotificationByTemplate($tokens, string $templateType, array $placeholders, string $type = '', $type_id = 0, string $image = '', ?int $orderStatusId = null, ?int $returnStatusId = null, ?string $payloadType = null, $payloadId = null, $payloadSlug = null, $orderItemId = null, bool $customerFacing = false)
    {
        if ($tokens->isEmpty()) {
            return;
        }

        $defaultLangId = self::getDefaultLanguageId();
        $languageService = app(LanguageService::class);

        // Null language_id: use current default so notification is always in a valid language
        $grouped = $tokens->groupBy(function ($t) use ($defaultLangId) {
            return $t->language_id ?? $defaultLangId;
        });

        foreach ($grouped as $langId => $langTokens) {
            $langPlaceholders = $placeholders;

            // Translate status_name per language when order/return status ID is provided
            if (isset($langPlaceholders['status_name']) && ($orderStatusId !== null || $returnStatusId !== null)) {
                $langCode = $languageService->getLanguageCode((int) $langId);
                if ($langCode) {
                    $previousLocale = App::getLocale();
                    App::setLocale($langCode);
                    if ($orderStatusId !== null) {
                        $langPlaceholders['status_name'] = $customerFacing
                            ? OrderStatusList::getCustomerTranslatedName($orderStatusId)
                            : OrderStatusList::getTranslatedName($orderStatusId);
                    } else {
                        $langPlaceholders['status_name'] = ReturnStatusList::getTranslatedName($returnStatusId);
                    }
                    App::setLocale($previousLocale);
                }
            }

            $content = self::getNotificationTemplateContent($templateType, (int) $langId);
            $title = self::replacePlaceholders($content['title'], $langPlaceholders);
            $message = self::replacePlaceholders($content['message'], $langPlaceholders);

            // Send to every token (all devices), not one per platform
            foreach ($langTokens as $t) {
                $arr = [$t->platform => $t->fcm_token];
                $result = self::sendNotification($arr, $title, $message, $type, $type_id, $image, $payloadType, $payloadId, $payloadSlug, $orderItemId);
                // FCM success responses carry a message resource "name".
                if (is_array($result) && isset($result['name'])) {
                    Log::info('[FCM] notification sent', [
                        'order_id'  => $payloadType === 'order' ? $payloadId : null,
                        'user_id'   => $t->user_id ?? null,
                        'platform'  => $t->platform,
                        'fcm_token' => $t->fcm_token,
                    ]);
                }
            }
        }
    }

    public static function sendOrderNotificationsToAdmins($order, $notification_type = 'new_order', $delivery_boy_id = null)
    {
        try {
            // Refresh order from database to ensure we have latest data, especially for order status updates
            $order = Order::with('items')->where('id', $order->id)->first();

            if (!$order) {
                Log::warning("Order not found in sendOrderNotificationsToAdmins", ['order_id' => $order->id ?? 'N/A']);
                return;
            }

            // Live-refresh the admin Orders page: broadcast when a new order lands
            // (covers placeOrder for COD/wallet + all gateway webhooks).
            if ($notification_type === 'new_order') {
                try {
                    event(new OrderPlaced($order));
                } catch (\Throwable $e) {
                    Log::error("OrderPlaced broadcast failed: " . $e->getMessage());
                }
            }

            $admin_ids_to_notify = [];

            // 1. Always notify super admins (role_id = 1)
            $super_admin_ids = Admin::where('role_id', 1)
                ->pluck('id')
                ->toArray();
            $admin_ids_to_notify = array_merge($admin_ids_to_notify, $super_admin_ids);

            // 2. Notify delivery boy if delivery_boy_id is provided or if order has one assigned
            $delivery_boy_id_to_use = $delivery_boy_id ?? ($order->delivery_boy_id ?? null);
            if ($delivery_boy_id_to_use && $delivery_boy_id_to_use != 0) {
                $delivery_boy = DeliveryBoy::where('id', $delivery_boy_id_to_use)->first();
                if ($delivery_boy && $delivery_boy->admin_id) {
                    $delivery_boy_admin = Admin::where('id', $delivery_boy->admin_id)
                        ->where('role_id', Role::$roleDeliveryBoy)
                        ->first();
                    if ($delivery_boy_admin) {
                        $admin_ids_to_notify[] = $delivery_boy_admin->id;
                    }
                }
            }

            // 3. Notify the store user(s) whose store serves this order's zone
            //    (one store = one zone). Super admins already covered above.
            if (!empty($order->zone_id)) {
                $store = Store::where('zone_id', $order->zone_id)->first();
                if ($store) {
                    $store_admin_ids = Admin::where('store_id', $store->id)
                        ->where('status', 1)
                        ->pluck('id')->toArray();
                    $admin_ids_to_notify = array_merge($admin_ids_to_notify, $store_admin_ids);
                }
            }

            // Remove duplicates and send notifications
            $admin_ids_to_notify = array_unique($admin_ids_to_notify);

            // Honour each admin/boy's push (Notification) toggle for this order status.
            $admin_ids_to_notify = array_values(array_filter(
                $admin_ids_to_notify,
                fn ($adminId) => self::checkOrderMailSendable($adminId, $order->active_status, 1, "Notification")
            ));

            if (!empty($admin_ids_to_notify)) {
                $admins = Admin::whereIn('id', $admin_ids_to_notify)->get();

                foreach ($admins as $admin) {
                    try {
                        $admin->notify(new OrderNotification($order->id, $notification_type));
                    } catch (\Exception $e) {
                        Log::error("Error sending notification to admin {$admin->id} for order #{$order->id}: " . $e->getMessage());
                    }
                }

                // Push FCM to the admins' registered panel devices so they get a live
                // notification (the panel's onMessage handler reacts to type 'new_order').
                try {
                    $isNewOrder = ($notification_type === 'new_order');
                    $fcmType = $isNewOrder ? 'new_order' : 'order';
                    $orderNumber = $order->order_number ?? $order->id;
                    $title = $isNewOrder ? __('new_order') : (__('order') . ' #' . $orderNumber);
                    $message = $isNewOrder
                        ? (__('new_order_received') . ' #' . $orderNumber)
                        : (__('order') . ' #' . $orderNumber . ' ' . __('updated'));

                    $adminTokens = AdminToken::whereIn('user_id', $admin_ids_to_notify)
                        ->whereNotNull('fcm_token')->get();
                    foreach ($adminTokens as $t) {
                        self::sendNotification(
                            [$t->platform ?? 'web' => $t->fcm_token],
                            $title,
                            $message,
                            $fcmType,
                            $order->id
                        );
                    }
                } catch (\Exception $e) {
                    Log::error("Error sending admin FCM for order #{$order->id}: " . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            Log::error("Error in sendOrderNotificationsToAdmins for order #{$order->id}: " . $e->getMessage());
        }
    }

    /**
     * Ledger messages are stored as translation keys (optionally "key|suffix" — the
     * suffix is appended verbatim, e.g. "withdrawal_request_approved|#12"). Translate
     * for API responses; unknown strings (legacy rows, admin free text) pass through.
     */
    public static function translateLedgerMessage($message)
    {
        if ($message === null || $message === '') {
            return $message;
        }
        $parts = explode('|', $message, 2);
        $translated = __($parts[0]);
        return isset($parts[1]) ? $translated . ' ' . $parts[1] : $translated;
    }

    public static function sendWalletNotification($user, $amount, string $pushTemplate, ?string $smsTemplate = null, array $extra = [], $countryId = null, $payloadId = null): void
    {
        try {
            $user = $user instanceof User ? $user : User::find($user);
            $amount = round((float) $amount, 2);
            if (!$user || $amount <= 0) {
                return;
            }

            $meta = self::rechargeWalletMeta($countryId, $user->id);
            $currency = $meta['currency'] ?: (Setting::get_value('currency') ?? '$');

            $placeholders = array_merge([
                'customer_name' => $user->name ?? '',
                'amount'   => $amount,
                'balance'  => self::getUserWalletBalance($user->id, $meta['country_id']),
                'currency' => $currency,
                'app_name' => Setting::get_value('app_name'),
            ], $extra);

            // Fire every enabled channel for this wallet event (mail/sms/push).
            $event = NotificationService::eventFromTemplate($pushTemplate);
            if ($event) {
                NotificationService::dispatch('customer', (int) $user->id, $event[0], [
                    'email'       => $user->email,
                    'phone'       => $user->mobile ? trim(($user->country_code ?? '') . $user->mobile) : null,
                    'tokens'      => UserToken::where('user_id', $user->id)->where('type', 'customer')->get(),
                    'language_id' => $user->language_id,
                ], $placeholders, ['payloadType' => 'wallet', 'payloadId' => $payloadId]);
            }
        } catch (\Throwable $e) {
            // Never let a notification failure roll back the money movement that triggered it.
            Log::error('sendWalletNotification error: ' . $e->getMessage());
        }
    }

    /**
     * Has a referrer's code hit the per-country usage limit (max number of first-order
     * bonuses it can earn)? A NULL/0 limit means UNLIMITED. Each successful referral use
     * is one 'wallet_refer_earn_first_order_bonus' ledger row for the referrer.
     */
    public static function referralLimitReached($referrerId, $country): bool
    {
        $limit = is_object($country) ? ($country->referral_usage_limit ?? null) : null;
        if ($limit === null || $limit === '' || (int) $limit <= 0) {
            return false; // unlimited
        }
        $used = WalletTransaction::where('user_id', $referrerId)
            ->where('message', 'wallet_refer_earn_first_order_bonus')
            ->count();
        return $used >= (int) $limit;
    }

    /** Wallet recharge payment failed — tell the customer their top-up didn't go through. */
    public static function sendWalletRechargeFailedNotification($user, $amount, $countryId = null): void
    {
        try {
            $user = $user instanceof User ? $user : User::find($user);
            if (!$user) {
                return;
            }
            $meta = self::rechargeWalletMeta($countryId, $user->id);
            $currency = $meta['currency'] ?: (Setting::get_value('currency') ?? '$');
            self::sendCustomerAccountNotification($user, 'wallet_recharge_failed', [
                'amount'   => round((float) $amount, 2),
                'currency' => $currency,
            ], ['payloadType' => 'wallet']);
        } catch (\Throwable $e) {
            Log::error('sendWalletRechargeFailedNotification error: ' . $e->getMessage());
        }
    }

    /**
     * Wallet recharge succeeded. Every gateway credits the wallet in its own webhook, so
     * this one-liner is called from each of them rather than from a single choke point.
     */
    public static function notifyWalletRecharge($userId, $amount, $countryId = null, $txnId = null): void
    {
        self::sendWalletNotification(
            $userId,
            $amount,
            'wallet_recharged_customer',
            'wallet_recharged_customer',
            ['txn_id' => $txnId ?? ''],
            $countryId
        );
    }

    /** Refund credited because an order/item was cancelled. */
    public static function notifyWalletRefundCancelled($order, $orderItem, $amount): void
    {
        self::sendWalletNotification(
            $order->user_id,
            $amount,
            'wallet_refund_cancelled_customer',
            'wallet_refund_cancelled_customer',
            [
                'order_id'     => $order->order_number ?? $order->id,
                'product_name' => $orderItem->product_name ?? '',
            ],
            $order->country_id
        );
    }

    /** Refund credited because a return was completed. */
    public static function notifyWalletRefundReturned($order, $orderItem, $amount, $returnRequestId = null): void
    {
        self::sendWalletNotification(
            $order->user_id,
            $amount,
            'wallet_refund_returned_customer',
            'wallet_refund_returned_customer',
            [
                'order_id'          => $order->order_number ?? $order->id,
                'product_name'      => $orderItem->product_name ?? '',
                'return_request_id' => $returnRequestId ?? '',
            ],
            $order->country_id
        );
    }

    /**
     * Notify a DELIVERY BOY that their wallet moved. Push only — SMS is customer-only.
     * `$reason` is the human-readable ledger message (order bonus, return commission, ...).
     */
    public static function sendDeliveryBoyWalletNotification($deliveryBoy, $amount, string $type, string $reason = ''): void
    {
        try {
            $deliveryBoy = $deliveryBoy instanceof DeliveryBoy ? $deliveryBoy : DeliveryBoy::find($deliveryBoy);
            $amount = round((float) $amount, 2);
            if (!$deliveryBoy || !$deliveryBoy->admin_id || $amount <= 0) {
                return;
            }

            $country = $deliveryBoy->country_id ? Country::find($deliveryBoy->country_id) : null;

            $placeholders = [
                'app_name'          => Setting::get_value('app_name'),
                'delivery_boy_name' => $deliveryBoy->name ?? '',
                'amount'   => $amount,
                'balance'  => round((float) $deliveryBoy->balance, 2),
                'currency' => $country->currency ?? (Setting::get_value('currency') ?? '$'),
                'reason'   => self::translateLedgerMessage($reason) ?: __('wallet_transaction'),
            ];

            $event = $type === DeliveryBoySettlement::$typeDebit ? 'wallet_debited' : 'wallet_credited';
            NotificationService::dispatch('delivery_boy', (int) $deliveryBoy->admin_id, $event, [
                'email'       => optional(Admin::find($deliveryBoy->admin_id))->email,
                'phone'       => $deliveryBoy->mobile ? trim(($deliveryBoy->country_code ?? '') . $deliveryBoy->mobile) : null,
                'tokens'      => AdminToken::where('user_id', $deliveryBoy->admin_id)->where('type', 'Delivery Boy')->get(),
                'language_id' => $deliveryBoy->language_id ?? null,
            ], $placeholders, ['payloadType' => 'wallet', 'payloadId' => $deliveryBoy->id]);
        } catch (\Throwable $e) {
            Log::error('sendDeliveryBoyWalletNotification error: ' . $e->getMessage());
        }
    }

    /** Dispatch recipient array (email/phone/tokens/language) for a customer User. */
    private static function customerRecipient(User $user): array
    {
        return [
            'email'       => $user->email,
            'phone'       => $user->mobile ? trim(($user->country_code ?? '') . $user->mobile) : null,
            'tokens'      => UserToken::where('user_id', $user->id)->where('type', 'customer')->get(),
            'language_id' => $user->language_id,
        ];
    }

    /**
     * Fire an account-level event to ONE customer across every enabled channel.
     * {app_name} + {customer_name} are always provided; $extra adds event-specific keys.
     */
    public static function sendCustomerAccountNotification($user, string $eventKey, array $extra = [], array $pushOpts = []): void
    {
        try {
            $user = $user instanceof User ? $user : User::find($user);
            if (!$user) {
                return;
            }
            $placeholders = array_merge([
                'app_name'      => Setting::get_value('app_name'),
                'customer_name' => $user->name ?? '',
            ], $extra);
            NotificationService::dispatch('customer', (int) $user->id, $eventKey, self::customerRecipient($user), $placeholders, $pushOpts);
        } catch (\Throwable $e) {
            Log::error("sendCustomerAccountNotification($eventKey) error: " . $e->getMessage());
        }
    }

    /** Welcome greeting right after a new customer registers. */
    public static function sendWelcomeNotification($user): void
    {
        self::sendCustomerAccountNotification($user, 'welcome');
    }

    /** Security alert when a signed-in customer changes their password. */
    public static function sendPasswordChangedNotification($user): void
    {
        self::sendCustomerAccountNotification($user, 'password_changed');
    }

    /** Customer account activated / deactivated by an admin. */
    public static function sendCustomerStatusNotification($user): void
    {
        $user = $user instanceof User ? $user : User::find($user);
        if (!$user) {
            return;
        }
        $statusName = ((int) $user->status === 1) ? __('active') : __('inactive');
        self::sendCustomerAccountNotification($user, 'account_status', ['status_name' => $statusName]);
    }

    /** Payment failed / order auto-cancelled by the gateway webhook. */
    public static function sendPaymentFailedNotification($order): void
    {
        try {
            $order = $order instanceof Order ? $order : Order::find($order);
            if (!$order) {
                return;
            }
            $user = User::find($order->user_id);
            if (!$user) {
                return;
            }
            $country = $order->country_id ? Country::find($order->country_id) : null;
            self::sendCustomerAccountNotification($user, 'payment_failed', [
                'order_id' => $order->order_number ?? $order->id,
                'amount'   => round((float) $order->total, 2),
                'currency' => $country->currency ?? (Setting::get_value('currency') ?? '$'),
            ], ['payloadType' => 'order', 'payloadId' => $order->id]);
        } catch (\Throwable $e) {
            Log::error('sendPaymentFailedNotification error: ' . $e->getMessage());
        }
    }

    /** A salary payout was recorded for a delivery boy. */
    public static function sendSalaryPaidNotification($deliveryBoy, $salary): void
    {
        try {
            $deliveryBoy = $deliveryBoy instanceof DeliveryBoy ? $deliveryBoy : DeliveryBoy::find($deliveryBoy);
            if (!$deliveryBoy || !$deliveryBoy->admin_id) {
                return;
            }
            $country = $deliveryBoy->country_id ? Country::find($deliveryBoy->country_id) : null;
            $placeholders = [
                'app_name'          => Setting::get_value('app_name'),
                'delivery_boy_name' => $deliveryBoy->name ?? '',
                'amount'   => round((float) $salary->amount, 2),
                'currency' => $country->currency ?? (Setting::get_value('currency') ?? '$'),
                'paid_on'  => $salary->paid_on ? (string) $salary->paid_on : '',
                'note'     => $salary->note ?? '',
            ];
            NotificationService::dispatch('delivery_boy', (int) $deliveryBoy->admin_id, 'salary_paid', [
                'email'       => optional(Admin::find($deliveryBoy->admin_id))->email,
                'phone'       => $deliveryBoy->mobile ? trim(($deliveryBoy->country_code ?? '') . $deliveryBoy->mobile) : null,
                'tokens'      => AdminToken::where('user_id', $deliveryBoy->admin_id)->where('type', 'Delivery Boy')->get(),
                'language_id' => $deliveryBoy->language_id ?? null,
            ], $placeholders, ['payloadType' => 'salary', 'payloadId' => $salary->id ?? 0]);
        } catch (\Throwable $e) {
            Log::error('sendSalaryPaidNotification error: ' . $e->getMessage());
        }
    }

    /**
     * Delivery boy account status changed by an admin (active/deactivated/rejected).
     * Replaces the old email-only delivery_boy_status template with the gated,
     * multi-channel account_status event.
     */
    public static function sendDeliveryBoyStatusNotification($deliveryBoy): void
    {
        try {
            $deliveryBoy = $deliveryBoy instanceof DeliveryBoy ? $deliveryBoy : DeliveryBoy::find($deliveryBoy);
            if (!$deliveryBoy || !$deliveryBoy->admin_id) {
                return;
            }
            $statusNames = [
                DeliveryBoy::$statusRegistered  => __('registered'),
                DeliveryBoy::$statusActive      => __('active'),
                DeliveryBoy::$statusRejected    => __('rejected'),
                DeliveryBoy::$statusDeactivated => __('deactivated'),
            ];
            $statusName = $statusNames[$deliveryBoy->status] ?? '';
            if ($statusName === '') {
                return;
            }
            $placeholders = [
                'app_name'          => Setting::get_value('app_name'),
                'delivery_boy_name' => $deliveryBoy->name ?? '',
                'status_name'       => $statusName,
            ];
            NotificationService::dispatch('delivery_boy', (int) $deliveryBoy->admin_id, 'account_status', [
                'email'       => optional(Admin::find($deliveryBoy->admin_id))->email,
                'phone'       => $deliveryBoy->mobile ? trim(($deliveryBoy->country_code ?? '') . $deliveryBoy->mobile) : null,
                'tokens'      => AdminToken::where('user_id', $deliveryBoy->admin_id)->where('type', 'Delivery Boy')->get(),
                'language_id' => $deliveryBoy->language_id ?? null,
            ], $placeholders);
        } catch (\Throwable $e) {
            Log::error('sendDeliveryBoyStatusNotification error: ' . $e->getMessage());
        }
    }

    /** Human-readable discount label for a promo code (e.g. "10%", "$5", "Free delivery"). */
    private static function promoDiscountLabel($promocode): string
    {
        $type = $promocode->discount_type ?? '';
        if ($type === 'free_delivery') {
            return __('free_delivery');
        }
        $val = rtrim(rtrim((string) ($promocode->discount ?? 0), '0'), '.');
        if ($type === 'percentage') {
            return $val . '%';
        }
        return (Setting::get_value('currency') ?? '$') . $val;
    }

    /**
     * Announce a newly-created promo code to its target customers.
     * Silent for hidden or inactive promos. audience_type: all | new | specific
     * ('new' = customers with no prior orders; 'specific' = the audience_ids list).
     */
    public static function sendPromoCodeNotification($promocode): void
    {
        try {
            $promocode = $promocode instanceof PromoCode ? $promocode : PromoCode::find($promocode);
            if (!$promocode) {
                return;
            }
            // Only announce publicly-visible, active promos.
            if ((int) $promocode->status !== 1 || ($promocode->visibility ?? 'public') !== 'public') {
                return;
            }

            $query = User::where('status', 1);
            $audienceType = $promocode->audience_type ?? 'all';
            if ($audienceType === 'specific') {
                $ids = is_array($promocode->audience_ids)
                    ? $promocode->audience_ids
                    : (json_decode($promocode->audience_ids ?? '[]', true) ?: []);
                if (empty($ids)) {
                    return;
                }
                $query->whereIn('id', $ids);
            } elseif ($audienceType === 'new') {
                // "new" = customers with no prior orders
                $query->whereNotIn('id', function ($q) {
                    $q->select('user_id')->from('orders')->whereNotNull('user_id');
                });
            }

            $discountLabel = self::promoDiscountLabel($promocode);
            $expiry = ((int) ($promocode->is_permanent ?? 0) === 1) ? '' : (string) ($promocode->end_date ?? '');
            $appName = Setting::get_value('app_name');

            $query->select('id', 'name', 'email', 'mobile', 'country_code', 'language_id', 'status')
                ->chunkById(200, function ($customers) use ($promocode, $discountLabel, $expiry, $appName) {
                    foreach ($customers as $user) {
                        NotificationService::dispatch('customer', (int) $user->id, 'promo_code', self::customerRecipient($user), [
                            'app_name'      => $appName,
                            'customer_name' => $user->name ?? '',
                            'promo_code'    => $promocode->promo_code ?? '',
                            'discount'      => $discountLabel,
                            'message'       => $promocode->title ?? '',
                            'expiry_date'   => $expiry,
                        ], ['payloadType' => 'promo_code', 'payloadId' => $promocode->id]);
                    }
                });
        } catch (\Throwable $e) {
            Log::error('sendPromoCodeNotification error: ' . $e->getMessage());
        }
    }

    /** Announce a newly-published blog post to all active customers. */
    public static function sendBlogNotification($blog): void
    {
        try {
            $blog = $blog instanceof Blog ? $blog : Blog::find($blog);
            if (!$blog || (int) $blog->status !== 1) {
                return;
            }
            $appName = Setting::get_value('app_name');
            $title = $blog->title ?? '';
            $slug = $blog->slug ?? '';
            // Full public blog link: {website_url}/blog/{slug}
            $websiteUrl = rtrim((string) Setting::get_value('website_url'), '/');
            $blogUrl = ($websiteUrl && $slug) ? $websiteUrl . '/blog/' . $slug : $slug;

            User::where('status', 1)
                ->select('id', 'name', 'email', 'mobile', 'country_code', 'language_id', 'status')
                ->chunkById(200, function ($customers) use ($appName, $title, $blogUrl, $blog) {
                    foreach ($customers as $user) {
                        NotificationService::dispatch('customer', (int) $user->id, 'new_blog', self::customerRecipient($user), [
                            'app_name'      => $appName,
                            'customer_name' => $user->name ?? '',
                            'blog_title'    => $title,
                            'blog_url'      => $blogUrl,
                        ], ['payloadType' => 'blog', 'payloadId' => $blog->id]);
                    }
                });
        } catch (\Throwable $e) {
            Log::error('sendBlogNotification error: ' . $e->getMessage());
        }
    }

    /**
     * A new withdrawal request landed — tell the super admins so they can action it.
     * Only delivery boys have withdrawals now (customer withdrawals removed).
     */
    public static function sendWithdrawalRequestAdminNotification($withdrawalRequest): void
    {
        try {
            // `origional_type` is the raw column; `type` is prettified by an accessor.
            if ($withdrawalRequest->origional_type !== WithdrawalRequest::$typeDeliveryBoy) {
                return;
            }
            $requester = DeliveryBoy::find($withdrawalRequest->type_id);
            $requesterName = $requester->name ?? '';

            $adminIds = Admin::where('role_id', Role::$roleSuperAdmin)->pluck('id')->toArray();
            if (empty($adminIds)) {
                return;
            }

            $placeholders = [
                'app_name'              => Setting::get_value('app_name'),
                'withdrawal_request_id' => $withdrawalRequest->id,
                'amount'            => round((float) $withdrawalRequest->amount, 2),
                'currency'          => $withdrawalRequest->currency ?: (Setting::get_value('currency') ?? '$'),
                'delivery_boy_name' => $requesterName,
            ];

            // Admin audience: gated by the admin master switch only (userId null).
            NotificationService::pushIfAllowed('admin', null, 'withdrawal_request',
                AdminToken::whereIn('user_id', $adminIds)->whereIn('type', [Role::$roleNameSuperAdmin, Role::$roleNameAdmin])->get(),
                $placeholders, ['payloadType' => 'withdrawal_request', 'payloadId' => $withdrawalRequest->id]);
            if (NotificationService::allowed('admin', null, 'withdrawal_request', 'mail')) {
                foreach (Admin::whereIn('id', $adminIds)->pluck('email') as $email) {
                    if ($email) {
                        self::sendMailByTemplate($email, 'withdrawal_request_admin', $placeholders);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('sendWithdrawalRequestAdminNotification error: ' . $e->getMessage());
        }
    }

    /** A withdrawal request was approved/rejected — tell whoever asked for it. */
    public static function sendWithdrawalStatusNotification($withdrawalRequest): void
    {
        try {
            $status = (int) $withdrawalRequest->status;
            if ($status === WithdrawalRequest::$statusPending) {
                return; // nothing decided yet
            }
            $statusName = $status === WithdrawalRequest::$statusApproved ? __('approved') : __('rejected');

            $placeholders = [
                'withdrawal_request_id' => $withdrawalRequest->id,
                'amount'      => round((float) $withdrawalRequest->amount, 2),
                'currency'    => $withdrawalRequest->currency ?: (Setting::get_value('currency') ?? '$'),
                'status_name' => $statusName,
                'remark'      => $withdrawalRequest->remark ?? '',
            ];

            // Only delivery boys have withdrawals now (customer withdrawals removed).
            // `origional_type` is the raw column; `type` is prettified by an accessor.
            if ($withdrawalRequest->origional_type !== WithdrawalRequest::$typeDeliveryBoy) {
                return;
            }
            $deliveryBoy = DeliveryBoy::find($withdrawalRequest->type_id);
            if (!$deliveryBoy || !$deliveryBoy->admin_id) {
                return;
            }
            $placeholders['app_name'] = Setting::get_value('app_name');
            $placeholders['delivery_boy_name'] = $deliveryBoy->name ?? '';

            NotificationService::dispatch('delivery_boy', (int) $deliveryBoy->admin_id, 'withdrawal_status', [
                'email'       => optional(Admin::find($deliveryBoy->admin_id))->email,
                'phone'       => $deliveryBoy->mobile ? trim(($deliveryBoy->country_code ?? '') . $deliveryBoy->mobile) : null,
                'tokens'      => AdminToken::where('user_id', $deliveryBoy->admin_id)->where('type', 'Delivery Boy')->get(),
                'language_id' => $deliveryBoy->language_id ?? null,
            ], $placeholders, ['payloadType' => 'withdrawal_request', 'payloadId' => $withdrawalRequest->id]);
        } catch (\Throwable $e) {
            Log::error('sendWithdrawalStatusNotification error: ' . $e->getMessage());
        }
    }

    public static function addDeliveryBoySettlement($delivery_boy_id, $amount, $type, $message = "")
    {
        $deliveryBoy = DeliveryBoy::find($delivery_boy_id);
        $amount = floatval($amount);

        // Nothing to move — a zero bonus is a no-op, not a ledger entry.
        if (!$deliveryBoy || $amount <= 0) {
            return null;
        }

        $opening_balance = floatval($deliveryBoy->balance);
        if ($type == DeliveryBoySettlement::$typeDebit) {
            $closing_balance = $opening_balance - $amount;
        } elseif ($type == DeliveryBoySettlement::$typeCredit) {
            $closing_balance = $opening_balance + $amount;
        } else {
            return null;
        }

        // Guard on the amount (above), never on `$closing_balance != 0` — a debit that
        // exactly zeroes the balance is a real movement and must still be recorded.
        DB::beginTransaction();
        try {
            $fundTransfer = new DeliveryBoySettlement();
            $fundTransfer->delivery_boy_id = $delivery_boy_id;
            $fundTransfer->type            = $type;
            $fundTransfer->opening_balance = $opening_balance;
            $fundTransfer->closing_balance = $closing_balance;
            $fundTransfer->amount          = $amount;
            $fundTransfer->message         = $message;
            $fundTransfer->save();

            $deliveryBoy->balance = $closing_balance;
            $deliveryBoy->save();

            DB::commit();

            // Every delivery-boy balance movement funnels through here, so this is the one
            // place the boy needs telling. Fires AFTER commit so a push failure can never
            // roll back the ledger.
            self::sendDeliveryBoyWalletNotification($deliveryBoy, $amount, $type, $message);

            return $fundTransfer;
        } catch (\Exception $e) {
            Log::error("addDeliveryBoySettlement error : " . $e->getMessage());
            DB::rollBack();
            return null;
        }
    }

    /**
     * Credit wallet-type promo cashback to the customer's wallet once the order is delivered.
     * Idempotent: only fires when the order is delivered, has cashback, and hasn't been credited.
     */
    public static function creditOrderCashback($order)
    {
        if (!($order instanceof Order)) {
            $order = Order::find($order);
        }
        if (!$order) {
            return;
        }
        if ((int) $order->active_status !== OrderStatusList::$delivered) {
            return;
        }
        if ((float) $order->cashback_amount <= 0 || (int) $order->cashback_credited === 1) {
            return;
        }

        $user = User::find($order->user_id);
        if (!$user) {
            return;
        }

        $amount = (float) $order->cashback_amount;
        $new_balance = (float) $user->balance + $amount;
        self::updateUserWalletBalance($new_balance, $order->user_id);

        WalletTransaction::insert([
            'order_id'   => $order->id,
            'user_id'    => $order->user_id,
            'type'       => 'credit',
            'amount'     => $amount,
            'message'    => __('promo_code_cashback_credited'),
            'status'     => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order->cashback_credited = 1;
        $order->save();

        self::sendWalletNotification(
            $user,
            $amount,
            'wallet_cashback_customer',
            'wallet_cashback_customer',
            ['order_id' => $order->order_number ?? $order->id],
            $order->country_id
        );
    }

    public static function updateOrderPromoCode($order_id, $order_promo_discount)
    {
        $order = Order::where("id", $order_id)->first();
        $order->promo_code = "";
        $order->promo_discount = 0;
        $order->final_total += $order_promo_discount;
        $order->remaining_final += $order_promo_discount;

        $order->save();
    }

    public static function uploadRatingImages($images, $product_rating_id)
    {
        foreach ($images as $file) {
            $fileName = time() . '_' . random_int(1111, 99999) . '.' . $file->getClientOriginalExtension();
            $image = Storage::disk('public')->putFileAs('product_ratings', $file, $fileName);
            $RatingImages = new RatingImages();
            $RatingImages->product_rating_id = $product_rating_id;
            $RatingImages->image = $image;
            $RatingImages->save();
        }
    }

    public static function productAverageRating($product_id)
    {
        $product_ratings = Product::with('ratings')->find($product_id);

        // Calculate the average rating
        $averageRating = (isset($product_ratings) && $product_ratings->ratings->count() > 0) ? $product_ratings->ratings->avg('rate') : 0;

        // Count the number of ratings
        $data['rating_count'] = isset($product_ratings) ? $product_ratings->ratings->count() : 0;
        $data['average_rating'] = $averageRating;
        $data['one_star_rating'] = ProductRating::where('product_id', $product_id)->where('rate', 1)->count() ?? 0;
        $data['two_star_rating'] = ProductRating::where('product_id', $product_id)->where('rate', 2)->count() ?? 0;
        $data['three_star_rating'] = ProductRating::where('product_id', $product_id)->where('rate', 3)->count() ?? 0;
        $data['four_star_rating'] = ProductRating::where('product_id', $product_id)->where('rate', 4)->count() ?? 0;
        $data['five_star_rating'] = ProductRating::where('product_id', $product_id)->where('rate', 5)->count() ?? 0;
        return $data;
    }

    public static function productRatingOfUser($product_id, $user_id)
    {
        $product_ratings = ProductRating::with('user', 'images')->where('product_id', $product_id)->where('user_id', $user_id)->get();
        return $product_ratings;
    }

    public static function getCategoryChildIds($categories)
    {
        $ids = [];

        foreach ($categories as $category) {
            $ids[] = $category['id'];
            if (!empty($category['cat_active_childs'])) {
                $ids = array_merge($ids, self::getCategoryChildIds($category['cat_active_childs']));
            }
        }

        return $ids;
    }

    public static function getGuestCartCount($variant_id, $quantity)
    {

        $total['cart_items_count'] = count($variant_id);
        $total['cart_total_qty'] = count($quantity);

        $totalAmt = CommonHelper::calculateTotalAmount($variant_id, $quantity);

        $total['save_price'] = $totalAmt['save_price'];
        $total['sub_total'] = $totalAmt['sub_total']; // Base price without tax
        $total['total_amount'] = $totalAmt['total_amount']; // Final amount with tax

        $total['product_variant_id'] = implode(',', $variant_id);
        $total['quantity'] = implode(',', $quantity);

        return $total;
    }
    
    public static function sendOrderItemStatusMailNotification($order_item, $type)
    {
        try {
            dispatch(new SendEmailJob($order_item, $type))->afterResponse();
        } catch (\Exception $e) {
            Log::error("Order Status order Send mail error :", [$e->getMessage()]);
        }
        try {
            dispatch(function () use ($order_item) {
                CommonHelper::sendNotificationOrderStatus($order_item, 'order_item_status_update');
                // Panel + FCM notification to super admins + the assigned boy, gated by their
                // push toggle for this status (instead of notifying every admin unconditionally).
                $order = Order::find($order_item->order_id);
                if ($order) {
                    CommonHelper::sendOrderNotificationsToAdmins($order, 'order_status_update', $order_item->delivery_boy_id ?? $order->delivery_boy_id ?? null);
                }
            })->afterResponse();
        } catch (\Exception $e) {
            Log::error("Order Status orderNotification error :", [$e->getMessage()]);
        }
    }
    public static function SendCartNotification()
    {
        $cart_notification = Setting::where('variable', 'cart_notification')->first();

        if ($cart_notification && (int) $cart_notification->value === 1) {
            // Settings store values as strings; Carbon 3 add* requires int|float.
            $delay_minutes = (int) (Setting::get_value('notification_delay_after_cart_addition') ?? 0);
            $interval_minutes = (int) (Setting::get_value('notification_interval') ?? 0);
            $stop_minutes = (int) (Setting::get_value('notification_stop_time') ?? 0);

            // Fetch cart items with their related variants
            $cartItems = Cart::with('variants')->orderBy('created_at', 'desc')->get();

            // Group by user, then keep only the latest item from each 10-minute window
            $groupedByUser = $cartItems->groupBy('user_id');
            $filteredItems = collect();

            foreach ($groupedByUser as $userId => $userItems) {
                $processed = [];
                foreach ($userItems as $item) {
                    $dominated = false;
                    foreach ($processed as $kept) {
                        // If this item was added within 10 minutes of an already-kept (newer) item, skip it
                        if (abs(Carbon::parse($item->created_at)->diffInMinutes(Carbon::parse($kept->created_at))) <= 10) {
                            $dominated = true;
                            break;
                        }
                    }
                    if (!$dominated) {
                        $processed[] = $item;
                    }
                }
                foreach ($processed as $p) {
                    $filteredItems->push($p);
                }
            }

            foreach ($filteredItems as $item) {
                $cartNotifications = CartNotification::where('cart_id', $item->id)->orderBy('id', 'desc')->first();

                if ($cartNotifications) {
                    if (Carbon::now()->format('Y-m-d H:i') == Carbon::parse($item->created_at)->addMinutes($stop_minutes)->format('Y-m-d H:i')) {
                        $cartNotifications->delete();
                    } elseif (Carbon::now()->format('Y-m-d H:i') == Carbon::parse($cartNotifications->sent_at)->addMinutes($interval_minutes)->format('Y-m-d H:i')) {
                        self::dispatchCartReminder($item, 'cart_reminder_interval');
                        CartNotification::create([
                            'user_id' => $item->user_id,
                            'cart_id' => $item->id,
                            'title' => 'Title for product ' . ($item->products->name ?? ''),
                            'message' => 'Message based on some condition for ' . ($item->products->name ?? ''),
                            'sent_at' => Carbon::now(),
                        ]);
                    }
                } else {
                    $twoMinutesLater = Carbon::parse($item->created_at)->addMinutes($delay_minutes);

                    if (Carbon::now()->format('Y-m-d H:i') == $twoMinutesLater->format('Y-m-d H:i')) {
                        Log::info('Next schedule:');
                        self::dispatchCartReminder($item, 'cart_reminder_first');
                        CartNotification::create([
                            'user_id' => $item->user_id,
                            'cart_id' => $item->id,
                            'title' => 'Hi, your cart with ' . ($item->products->name ?? '') . ' is waiting for you!',
                            'message' => "Don't forget to complete your purchase and place your order today!",
                            'sent_at' => Carbon::now(),
                        ]);
                    }
                }
            }
        }
    }

    /** Fire a cart-reminder event to the cart's owner across every enabled channel. */
    private static function dispatchCartReminder($item, string $event): void
    {
        $user = User::find($item->user_id);
        if (!$user) {
            return;
        }
        NotificationService::dispatch('customer', (int) $item->user_id, $event, [
            'email'       => $user->email,
            'phone'       => $user->mobile ? trim(($user->country_code ?? '') . $user->mobile) : null,
            'tokens'      => UserToken::where('user_id', $item->user_id)->where('type', 'customer')->get(),
            'language_id' => $user->language_id,
        ], ['app_name' => Setting::get_value('app_name'), 'product_name' => $item->products->name ?? ''], ['payloadType' => 'cart']);
    }

    public static function sendSmsOrderStatus($order, $order_item_status)
    {
        try {
            $statusId = (int) $order_item_status;

            // An OrderItem carries `order_id`; an Order does not.
            $isItem = isset($order->order_id);

            $type = $isItem ? self::orderItemSmsType($statusId) : self::orderSmsType($statusId);
            if (!$type) {
                return;
            }

            $user = User::find($order->user_id);
            if (!$user || empty($user->mobile)) {
                Log::warning("SMS not sent for order #{$order->id}: customer mobile missing.");
                return;
            }

            // SMS is customer-only; honour the customer's own per-status toggle.
            if (!self::isSmsStatus($statusId, $user->id)) {
                Log::info("SMS skipped for order #{$order->id}: SMS disabled for status {$statusId}.");
                return;
            }

            $phone = trim(($user->country_code ?? '') . $user->mobile);
            $orderId = $order->order_id ?? $order->id; // OrderItem -> order_id, Order -> id
            $placeholders = [
                'customer_name' => $user->name ?? '',
                'order_id'      => Order::where('id', $orderId)->value('order_number') ?: $orderId,
                'currency'      => $order->currency ?? (Setting::get_value('currency') ?: ''),
                'final_total'   => $order->final_total ?? '',
            ];

            if ($isItem) {
                $placeholders['product_name'] = $order->product_name ?? '';
                // SMS is customer-only → "Order Placed" instead of "Received".
                $placeholders['status_name']  = OrderStatusList::getCustomerTranslatedName($statusId);
            }

            SmsHelper::sendByTemplate($phone, $type, $placeholders, $user->language_id);
        } catch (\Exception $e) {
            Log::error("Error sending SMS for order #{$order->id}: " . $e->getMessage());
        }
    }

    public static function orderItemSmsType(int $statusId): ?string
    {
        if ($statusId === OrderStatusList::$cancelled) {
            return 'order_item_cancelled_customer';
        }
        if ($statusId === OrderStatusList::$returned) {
            return 'order_item_returned_customer';
        }
        // Only statuses an ecommerce item can actually reach.
        $itemStatuses = [
            OrderStatusList::$paymentPending,
            OrderStatusList::$received,
            OrderStatusList::$processed,
            OrderStatusList::$shipped,
            OrderStatusList::$outForDelivery,
            OrderStatusList::$delivered,
        ];
        return in_array($statusId, $itemStatuses, true) ? 'order_item_status_customer' : null;
    }

    /** OrderStatusList id -> customer SMS template type. */
    public static function orderSmsType(int $statusId): ?string
    {
        $map = [
            OrderStatusList::$paymentPending => 'order_status_payment_pending_customer',
            OrderStatusList::$received       => 'order_status_received_customer',
            OrderStatusList::$processed      => 'order_status_processed_customer',
            OrderStatusList::$shipped        => 'order_status_shipped_customer',
            OrderStatusList::$outForDelivery => 'order_status_out_for_delivery_customer',
            OrderStatusList::$delivered      => 'order_status_delivered_customer',
            OrderStatusList::$cancelled      => 'order_status_cancelled_customer',
            OrderStatusList::$returned       => 'order_status_returned_customer',
            OrderStatusList::$preparing      => 'order_status_preparing_customer',
            OrderStatusList::$readyForPickup => 'order_status_ready_for_pickup_customer',
            OrderStatusList::$pickedUp       => 'order_status_picked_up_customer',
        ];
        return $map[$statusId] ?? null;
    }

    // Customer order-status SMS gate, backed by the notification catalog.
    public static function isSmsStatus($active_status, $user_id)
    {
        $eventKey = NotificationService::orderStatusEventKey((int) $active_status);
        if (!$eventKey) {
            return false;
        }
        return NotificationService::allowed('customer', (int) $user_id, $eventKey, 'sms');
    }

    public static function getChildCategoryIds($categoryIds)
    {
        $childIds = Category::whereIn('parent_id', $categoryIds)
            ->pluck('id')
            ->toArray();

        if (!empty($childIds)) {
            return array_merge($childIds, self::getChildCategoryIds($childIds));
        }

        return [];
    }

    public static function AdditionalChargesArray($order)
    {
        foreach (['additional_charges', 'surge_charges'] as $field) {
            if (isset($order->$field)) {
                if (is_string($order->$field)) {
                    $decoded = json_decode($order->$field, true);
                    $order->$field = (is_array($decoded)) ? $decoded : [];
                } elseif (!is_array($order->$field)) {
                    $order->$field = [];
                }
            } else {
                $order->$field = [];
            }
        }
    }

    public static function translate(array $text, array $onlyCodes = []): array
    {

        if (empty($text)) {
            return [];
        }

        // Get Gemini API Key
        $apiKey = Setting::get_value('text_gen_key');
        if (empty($apiKey)) {
            return [];
        }

        try {
            $languages = DB::table('languages as l')
                ->join('supported_languages as sl', 'sl.id', '=', 'l.supported_language_id')
                ->where('l.status', 1)
                ->where('l.system_type', 4)
                ->whereNotNull('sl.code')
                // Only the languages the caller asked for, so translating one language
                // doesn't pay for every other one.
                ->when(!empty($onlyCodes), fn ($q) => $q->whereIn('sl.code', $onlyCodes))
                ->get([
                    'sl.code',
                    'sl.name'
                ]);
        } catch (\Exception $e) {
            Log::error('Language fetch failed', [
                'error' => $e->getMessage()
            ]);
            return [];
        }

        if ($languages->isEmpty()) {
            Log::warning('No active supported languages found');
            return [];
        }

        // For long text (e.g. privacy policy, terms), translate one field at a time to avoid output token limit
        $totalChars = 0;
        foreach ($text as $value) {
            $totalChars += strlen((string) $value);
        }
        $longTextThreshold = 4000;

        if ($totalChars < $longTextThreshold) {
            return self::translateSingleRequest($text, $languages, $apiKey, $totalChars);
        }

        $mergedResult = [];
        foreach ($text as $fieldName => $fieldValue) {
            $singleField = [$fieldName => $fieldValue];
            $chunkResult = self::translateSingleRequest($singleField, $languages, $apiKey, strlen((string) $fieldValue));
            if (!empty($chunkResult)) {
                if (isset($chunkResult[0]['error'])) {
                    return $chunkResult;
                }
                foreach ($chunkResult as $langCode => $translated) {
                    if (!isset($mergedResult[$langCode])) {
                        $mergedResult[$langCode] = [];
                    }
                    $mergedResult[$langCode] = array_merge($mergedResult[$langCode], $translated);
                }
            }
        }

        return $mergedResult;
    }

    /**
     * Single Gemini API request for translation. Used for chunking long content.
     */
    private static function translateSingleRequest(array $text, $languages, string $apiKey, int $totalChars = 0): array
    {
        $languageList = [];
        foreach ($languages as $lang) {
            $languageList[] = [
                'code' => strtolower(trim($lang->code)),
                'name' => trim($lang->name),
            ];
        }

        $prompt = "
You are a professional translation engine.

STRICT RULES:
1. Translate ALL values into EACH language listed.
2. Use EXACT language codes as provided.
3. DO NOT change, shorten, or guess language codes.
4. JSON keys MUST remain unchanged.
5. Translate ONLY values, NEVER keys.
6. Output ONLY valid JSON.
7. No markdown, no explanation, no extra text.

Output format:
{
  \"lang_code\": {
    \"field\": \"translated value\"
  }
}

Languages:
" . json_encode($languageList, JSON_UNESCAPED_UNICODE) . "

JSON:
" . json_encode($text, JSON_UNESCAPED_UNICODE);

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $apiKey;

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'response_mime_type' => 'application/json',
                'maxOutputTokens' => 65536,
            ]
        ];

        $timeout = $totalChars > 6000 ? 180 : 120;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        unset($ch);

        if ($curlError || $httpCode !== 200 || empty($response)) {
            return [['error' => ['code' => $httpCode, 'message' => $curlError ?: 'API request failed']]];
        }

        $data = json_decode($response, true);

        if (
            empty($data['candidates'][0]['content']['parts'][0]['text'])
        ) {
            return [];
        }

        $jsonText = trim($data['candidates'][0]['content']['parts'][0]['text']);
        $jsonText = preg_replace('/^```json|```$/i', '', $jsonText);
        $jsonText = trim($jsonText);

        $decoded = json_decode($jsonText, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }

        return $decoded;
    }

    private const TRANSLATED_SETTING_KEYS = [
        'app_name',
        'site_title',
        'store_address',
        'copyright_details',
        'app_title',
        'app_short_description',
        'app_mode_customer_remark',
        'app_mode_delivery_boy_remark',
        'contact_us',
        'about_us',
        'privacy_policy',
        'return_policy',
        'shipping_policy',
        'cancellation_policy',
        'terms_conditions',
        'common_meta_title',
        'common_meta_description',
        'website_mode_remark',
        'terms_conditions_delivery_boy',
        'privacy_policy_delivery_boy',
        'cookie_consent_title',
        'cookie_consent_description'
    ];

    public static function resolveTranslatedSettings(array $data): array
    {
        $lang = app()->has('lang_code') ? app('lang_code') : 'en';
        $languageService = app(LanguageService::class);
        $defaultLang = $languageService->getDefaultLanguage();
        $defaultLangCode = $defaultLang ? $languageService->getLanguageCode($defaultLang->id) : 'en';
        if (!$defaultLangCode) {
            $defaultLangCode = 'en';
        }

        foreach (self::TRANSLATED_SETTING_KEYS as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if (is_string($value)) {
                if ($value === '') {
                    continue;
                }
                $decoded = json_decode($value, true);
            } elseif (is_array($value)) {
                $decoded = $value;
            } else {
                continue;
            }

            if (!is_array($decoded) || empty($decoded)) {
                continue;
            }
            $keys = array_keys($decoded);
            $isNumericList = $keys === range(0, count($decoded) - 1);
            if ($isNumericList) {
                continue;
            }
            // Resolve to single language: current lang, or default lang if current is empty/missing
            $resolved = $decoded[$lang] ?? null;
            if ($resolved === null || $resolved === '') {
                $resolved = $decoded[$defaultLangCode] ?? $decoded['en'] ?? reset($decoded) ?: '';
            }
            $data[$key] = $resolved === null ? '' : (string) $resolved;
        }
        return $data;
    }

    /**
     * Country-scoped, multi-language policy values resolved to the current request
     * language (falling back to the country's default-language row). Policies moved
     * off global settings onto country_translations.
     *
     * @return array<string,string> field => html string
     */
    public static function countryPolicies(?int $countryId, array $fields): array
    {
        $out = array_fill_keys($fields, '');
        if (!$countryId) {
            return $out;
        }

        $lang = app()->has('lang_code') ? app('lang_code') : 'en';
        $langId = optional(app(LanguageService::class)->getLanguageByCode($lang))->id;
        $defLangId = Language::where('is_default', 1)->value('id');

        $row = $langId
            ? CountryTranslation::where('country_id', $countryId)->where('language_id', $langId)->first()
            : null;
        $defRow = null;
        $country = null;

        foreach ($fields as $f) {
            $val = $row ? $row->$f : null;
            if (empty($val)) {
                if ($defRow === null && $defLangId) {
                    $defRow = CountryTranslation::where('country_id', $countryId)
                        ->where('language_id', $defLangId)->first();
                }
                $val = $defRow ? $defRow->$f : null;
            }
            if (empty($val)) {
                // Final fallback: default-language value on the countries base table.
                if ($country === null) {
                    $country = Country::find($countryId);
                }
                $val = $country ? $country->$f : null;
            }
            $out[$f] = (string) ($val ?? '');
        }
        return $out;
    }

    /**
     * Generate a unique SKU automatically
     * 
     * @param string|null $productName Optional product name to base SKU on
     * @param int|null $productId Optional product ID to include in SKU
     * @return string Unique SKU
     */
    public static function generateUniqueSku(?string $productName = null, ?int $productId = null): string
    {
        // Base SKU generation
        if ($productName) {
            // Clean product name: remove special chars, convert to uppercase, limit to 15 chars
            $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $productName));
            $base = substr($base, 0, 15);
        } else {
            $base = 'PROD';
        }

        // Add product ID if available
        if ($productId) {
            $base .= '-' . $productId;
        }

        // Add timestamp for uniqueness
        $timestamp = now()->format('YmdHis');
        $sku = $base . '-' . $timestamp;

        // Ensure uniqueness (add random suffix if needed)
        $attempt = 0;
        while (ProductVariant::where('sku', $sku)->exists() && $attempt < 10) {
            $sku = $base . '-' . $timestamp . '-' . strtoupper(substr(md5(rand()), 0, 4));
            $attempt++;
        }

        return $sku;
    }

    /**
     * Ensure a SKU is unique, auto-generate if empty or duplicate
     * 
     * @param string|null $sku The SKU to check
     * @param int|null $variantId Variant ID to exclude from duplicate check (for updates)
     * @param string|null $productName Product name for auto-generation
     * @param int|null $productId Product ID for auto-generation
     * @return string Unique SKU
     */
    public static function ensureUniqueSku(?string $sku, ?int $variantId = null, ?string $productName = null, ?int $productId = null): string
    {
        // If SKU is empty, generate one
        if (empty(trim($sku ?? ''))) {
            return self::generateUniqueSku($productName, $productId);
        }

        // Check if SKU is already in use
        $query = ProductVariant::where('sku', trim($sku));
        if ($variantId) {
            $query->where('id', '!=', $variantId);
        }

        // If not in use, return as-is
        if (!$query->exists()) {
            return trim($sku);
        }

        // SKU is duplicate, generate a new one
        return self::generateUniqueSku($productName, $productId);
    }
}
