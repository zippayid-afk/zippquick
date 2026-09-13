<?php

namespace App\Http\Controllers\API;

use App\Helpers\CommonHelper;
use App\Helpers\EnvHelper;
use App\Http\Controllers\Controller;
use App\Models\ApiCallTracking;
use Illuminate\Http\Request;
use App\Models\Setting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;

class GeneralSettingsApiController extends Controller
{
    /** Realtime chat drivers the panel can switch between. */
    private const BROADCAST_DRIVERS = ['reverb', 'pusher'];

    public function index()
    {
        $countries = json_decode(file_get_contents(base_path('config/countries_currency.json')), true);
        $store_settingsArray = array(
            "system_configurations" => 1,
            "system_configurations_id" => "13",
            "app_name" => "",
            "support_number" => "",
            "support_email" => "",
            "order_prefix" => "",
            "return_request_prefix" => "",
            "invoice_prefix" => "",

            "is_version_system_on" => 0,
            "required_force_update" => 0,
            "current_version" => "1.0.0",

            "ios_is_version_system_on" => 0,
            "ios_required_force_update" => 0,
            "ios_current_version" => "1.0.0",

            "customer_light_mode_color" => "#0E9623",
            "customer_dark_mode_color" => "#1A2A3A",
            "delivery_boy_light_mode_color" => "#0E9623",
            "delivery_boy_dark_mode_color" => "#1A2A3A",

            "logo" => "",
            "admin_favicon" => "",
            "copyright_details" => "",
            "store_address" => "",
            'map_latitude' => "",
            "map_longitude" => "",
            "currency" => "",
            "currency_code" => "",
            "decimal_point" => "",
            "default_city_id" => 0,

            "max_cart_items_count" => "",
            "product_rating" => 0,
            "date_format" => "d-m-Y",
            "time_format" => "h:i A",

            "delivery_boy_bonus_settings" => 0,
            "delivery_boy_bonus_type" => 0,
            "delivery_boy_bonus_percentage" => 0,
            "delivery_boy_bonus_min_amount" => 0,
            "delivery_boy_bonus_max_amount" => 0,

            "from_mail" => "",
            "reply_to" => "",
            "generate_otp" => 0,

            "mailer" => "smtp",
            "smtp_from_mail" => "",
            "smtp_reply_to" => "",
            "smtp_email_password" => "",
            "smtp_host" => "",
            "smtp_port" => "",
            "smtp_content_type" => "",
            "smtp_encryption_type" => "",
            "google_place_api_key" => "",
            "google_map_api_key" => "",
            "apiKey" => "",
            "googleMapApiKey" => "",
            "text_gen_key" => "",
            "map_provider" => "osm",
            "playstore_url" => "",
            "appstore_url" => "",
            "delivery_boy_playstore_url" => "",
            "delivery_boy_appstore_url" => "",
            "deeplink_schema" => "",
            "phone_login" => "",
            "google_login" => "",
            "apple_login" => "",
            "email_login" => "",
            "panel_login_background_img",
            "notification_delay_after_cart_addition",
            "notification_interval",
            "notification_stop_time",
            "cashfree_status" => 0,
            "cashfree_mode" => "test",
            "cashfree_app_id" => "",
            "cashfree_secret_key" => "",
            "cashfree_title" => "Cashfree Payment",
            "cashfree_logo" => "",
            "cloudinary_cloud_name" => "",
            "cloudinary_api_key" => "",
            "cloudinary_api_secret" => "",
        );
        $variables = array_keys($store_settingsArray);
        $store_settings = Setting::whereIn('variable', $variables)->get();

        $login_settingsArray = array(
            "phone_login" => "",
            "google_login" => "",
            "apple_login" => "",
            "email_login" => "",
            "phone_auth_otp" => "",
            "phone_auth_password" => "",
            "firebase_authentication" => "",
            "custom_sms_gateway_otp_based" => "",
            "password_min_length" => "",
            "password_max_length" => "",
            "password_require_uppercase" => "",
            "password_require_lowercase" => "",
            "password_require_number" => "",
            "password_require_special" => "",
        );
        $login_variables = array_keys($login_settingsArray);
        $login_settings = Setting::whereIn('variable', $login_variables)->get();

        // Refer & Earn is country-wise now (configured on the Country form).

        $store_settings = $this->maskConfidentialSettings($store_settings);
        $login_settings = $this->maskConfidentialSettings($login_settings);

        $data = array(
            "store_settingsObject" => $store_settingsArray,
            "currency_code" => $countries,
            "store_settings" => $store_settings,
            "login_settings" => $login_settings
        );
        return CommonHelper::responseWithData($data);
    }

    public function getPasswordPolicy()
    {
        $min = (int) (Setting::get_value('password_min_length') ?: 0);
        $bool = fn ($key) => in_array((string) Setting::get_value($key), ['1', 'true', 'on'], true) ? 1 : 0;
        return CommonHelper::responseWithData([
            'min_length'        => $min > 0 ? $min : 5,
            'max_length'        => (int) (Setting::get_value('password_max_length') ?: 0),
            'require_uppercase' => $bool('password_require_uppercase'),
            'require_lowercase' => $bool('password_require_lowercase'),
            'require_number'    => $bool('password_require_number'),
            'require_special'   => $bool('password_require_special'),
        ]);
    }

    public function clearCache()
    {
        $data = [];
        foreach (['cache:clear' => 'cache', 'config:clear' => 'config', 'route:clear' => 'route', 'view:clear' => 'view'] as $command => $key) {
            Artisan::call($command);
            $data[$key] = Artisan::output();
        }
        return CommonHelper::responseSuccessWithData('Cache cleared successfully!', $data);
    }

    public function save_login_setting(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'at_least_one' => 'At least one of phone login or google login must be enabled.',
        ]);

        $validator->after(function ($validator) use ($request) {
            // Validate that at least one of phone_login or google_login is enabled
            if (!$request->phone_login && !$request->google_login) {
                $validator->errors()->add('phone_login', 'At least one of phone login or google login must be enabled.');
                $validator->errors()->add('google_login', 'At least one of phone login or google login must be enabled.');
            }

            // Additional validation if phone_login is enabled
            if ($request->phone_login) {
                if (!$request->firebase_authentication && !$request->custom_sms_gateway_otp_based) {
                    $validator->errors()->add('firebase_authentication', 'When phone login is enabled, either Firebase Authentication or Custom SMS Gateway OTP Based must be enabled.');
                    $validator->errors()->add('custom_sms_gateway_otp_based', 'When phone login is enabled, either Firebase Authentication or Custom SMS Gateway OTP Based must be enabled.');
                }
            }

            // Custom SMS Gateway OTP needs an active SMS gateway to actually send the OTP.
            if ($request->custom_sms_gateway_otp_based) {
                $smsGateway = Setting::where('variable', 'sms_gateway')->value('value');
                if (empty(trim((string) $smsGateway))) {
                    $validator->errors()->add('custom_sms_gateway_otp_based', 'Enable an SMS gateway in SMS Settings before turning on Custom SMS Gateway OTP based login.');
                }
            }
        });

        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        foreach ($request->all() as $key => $value) {
            $value = $value ?? " ";
            $setting = Setting::where('variable', $key)->first();
            if ($setting) {
                $setting->variable = $key;
                $setting->value = $value;

                $setting->save();
            } else {
                $setting = new Setting();
                $setting->variable = $key;
                $setting->value = $value;

                $setting->save();
            }
        }
        return CommonHelper::responseSuccess(__('login_settings_saved_successfully'));
    }

    /**
     * Save store basic settings (logo, app name, support details, etc.)
     */
    public function save_store_basic_setting(Request $request)
    {
        // store_address lives with the store basics now (it is a single translatable
        // field, not a settings area of its own).
        $translatable = ['copyright_details', 'store_address'];


        // Validate only if default language fields are empty
        $validator = Validator::make($request->all(), [
            'logo' => $request->hasFile('logo') ? 'mimes:jpeg,jpg,png,gif,svg,webp' : '',
            'admin_favicon' => $request->hasFile('admin_favicon') ? 'mimes:jpeg,jpg,png,gif,ico,svg,webp' : '',
            'panel_login_background_img' => $request->hasFile('panel_login_background_img') ? 'mimes:jpeg,jpg,png,gif,svg,webp' : '',
            'copyright_details' . config('app.default_language') => 'required|string',
        ]);

        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        $store_basic_variables = [
            'system_configurations',
            'system_configurations_id',
            'app_name',
            'support_number',
            'support_email',
            'logo',
            'admin_favicon',
            'panel_login_background_img',
            'copyright_details',
            'store_address',
            'max_cart_items_count',
            'product_rating',
            'admin_theme_color',
            'order_prefix',
            'return_request_prefix',
            'invoice_prefix'
        ];

        foreach ($store_basic_variables as $key) {

            $setting = Setting::firstOrNew(['variable' => $key]);

            // FILES (directory is the setting key; old stored file is replaced/deleted)
            if ($request->hasFile($key)) {
                $setting->value = CommonHelper::uploadFile($request, $key, $key, $setting->value);
                $setting->save();
                continue;
            }

            // TRANSLATABLE
            if (in_array($key, $translatable) && $request->has($key)) {
                $setting->value = $this->cleanMultilangValue($request->$key, $setting->value);
                $setting->save();
                continue;
            }

            // NORMAL
            if ($request->has($key)) {
                $setting->value = trim($request->$key);
                $setting->save();
            }
        }

        return CommonHelper::responseSuccess('store_settings_saved_successfully');
    }

    private function cleanMultilangValue($value, $oldValue = null)
    {
        if (empty($value)) {
            return $oldValue ?? "";
        }

        $decoded = json_decode($value, true);
        $existing = json_decode($oldValue, true) ?? [];

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            foreach ($decoded as $lang => $text) {
                if ($text === null || $text === '') {
                    continue; // preserve old translation
                }

                $text = preg_replace('/\r\n|\r|\n/', ' ', $text);
                $text = preg_replace('/\s+/', ' ', $text);
                $existing[$lang] = trim($text);
            }

            return json_encode(
                $existing,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        return trim($value);
    }


    /**
     * Save address settings (store address, coordinates, currency, timezone, etc.)
     */
    public function save_address_setting(Request $request)
    {
        $translatable = ['store_address'];

        $address_variables = [
            'store_address',
            'map_latitude',
            'map_longitude',
            'currency',
            'currency_code',
            'decimal_point',
            'default_city_id'
        ];

        foreach ($address_variables as $key) {

            if (!array_key_exists($key, $request->all())) {
                continue;
            }

            $setting = Setting::firstOrNew(['variable' => $key]);

            if (in_array($key, $translatable)) {
                $value = $this->cleanMultilangValue(
                    $request->$key,
                    $setting->value
                );
                $setting->value = $value !== '' ? $value : json_encode([]);
            } else {
                $setting->value = trim($request->$key ?? '');
            }

            $setting->save();
        }

        return CommonHelper::responseSuccess('address_settings_saved_successfully');
    }

    /**
     * Save other settings (cart limits, order amounts, stock limits, etc.)
     */
    public function save_other_setting(Request $request)
    {
        $other_variables = [
            'max_cart_items_count',
            'product_rating',
            'date_format',
            'time_format',
            'country_code',
            'admin_theme_color',
        ];

        foreach ($request->all() as $key => $value) {
            if (in_array($key, $other_variables)) {
                $value = $value ?? " ";
                $setting = Setting::where('variable', $key)->first();

                if ($setting) {
                    $setting->variable = $key;
                    $setting->value = $value;
                    $setting->save();
                } else {
                    $setting = new Setting();
                    $setting->variable = $key;
                    $setting->value = $value;
                    $setting->save();
                }
            }
        }

        return CommonHelper::responseSuccess('other_settings_saved_successfully');
    }

    /**
     * Save delivery boy settings (bonus settings, OTP system, etc.)
     */
    public function save_delivery_boy_setting(Request $request)
    {
        $delivery_boy_variables = [
            'delivery_boy_bonus_settings',
            'delivery_boy_bonus_type',
            'delivery_boy_bonus_percentage',
            'delivery_boy_bonus_min_amount',
            'delivery_boy_bonus_max_amount',
            'generate_otp'
        ];

        foreach ($request->all() as $key => $value) {
            if (in_array($key, $delivery_boy_variables)) {
                $value = $value ?? " ";
                $setting = Setting::where('variable', $key)->first();

                if ($setting) {
                    $setting->variable = $key;
                    $setting->value = $value;
                    $setting->save();
                } else {
                    $setting = new Setting();
                    $setting->variable = $key;
                    $setting->value = $value;
                    $setting->save();
                }
            }
        }

        return CommonHelper::responseSuccess('delivery_boy_settings_saved_successfully');
    }

    /**
     * Save deeplink settings (URL schema used by the mobile apps).
     */
    public function save_deeplink_setting(Request $request)
    {
        $deeplink_variables = [
            'deeplink_schema',
        ];

        foreach ($request->all() as $key => $value) {
            if (in_array($key, $deeplink_variables)) {
                $setting = Setting::where('variable', $key)->first() ?? new Setting();
                $setting->variable = $key;
                $setting->value = $value ?? '';
                $setting->save();
            }
        }
        return CommonHelper::responseSuccess('deeplink_settings_saved_successfully');
    }

    /**
     * Save app settings (app modes, version control, store URLs, etc.)
     */
    public function save_app_setting(Request $request)
    {
        $appVariables = [
            'playstore_url',
            'appstore_url',
            'delivery_boy_playstore_url',
            'delivery_boy_appstore_url',
            'is_version_system_on',
            'required_force_update',
            'current_version',
            'ios_is_version_system_on',
            'ios_required_force_update',
            'ios_current_version',
            'customer_light_mode_color',
            'customer_dark_mode_color',
            'delivery_boy_light_mode_color',
            'delivery_boy_dark_mode_color',
            'delivery_boy_bonus_settings',
            'delivery_boy_bonus_type',
            'delivery_boy_bonus_percentage',
            'delivery_boy_bonus_min_amount',
            'delivery_boy_bonus_max_amount',
            'generate_otp',
        ];

        $translatable = [];

        foreach ($appVariables as $key) {
            if (!$request->has($key)) continue;

            $setting = Setting::where('variable', $key)->first() ?? new Setting();
            $setting->variable = $key;

            if (in_array($key, $translatable)) {

                $setting->value = $this->cleanMultilangValue(
                    $request->$key,
                    $setting->value
                );
            } else {
                $value = $request->$key;
                $setting->value = ($value === null || $value === 'null') ? '' : $value;
            }

            $setting->save();
        }

        return CommonHelper::responseSuccess('app_settings_saved_successfully');
    }

    private array $maintenanceSurfaces = [
        'website_mode'          => 'web',
        'app_mode_customer'     => 'customer',
        'app_mode_delivery_boy' => 'delivery_boy',
    ];

    /** Read all maintenance settings (mode, multilang remark, schedule) per surface. */
    public function maintenance_setting()
    {
        $out = [];
        foreach ($this->maintenanceSurfaces as $toggle => $surface) {
            $remarkRaw = Setting::where('variable', $toggle . '_remark')->value('value');
            $remark = [];
            if (!empty($remarkRaw)) {
                $decoded = json_decode($remarkRaw, true);
                $remark = is_array($decoded) ? $decoded : ['en' => (string) $remarkRaw];
            }
            $out[$surface] = [
                'mode'   => (int) (Setting::get_value($toggle) ?: 0),
                'remark' => (object) $remark,
                'start'  => (string) (Setting::get_value($toggle . '_start') ?: ''),
                'end'    => (string) (Setting::get_value($toggle . '_end') ?: ''),
            ];
        }
        return CommonHelper::responseWithData($out);
    }

    /**
     * Save maintenance settings for all three surfaces. Toggle changes broadcast
     * live; the remark is multilanguage; start/end drive the scheduler.
     */
    public function save_maintenance_setting(Request $request)
    {
        foreach ($this->maintenanceSurfaces as $toggle => $surface) {
            // Multilang remark (merged, whitespace-normalised).
            $remarkKey = $toggle . '_remark';
            if ($request->has($remarkKey)) {
                $s = Setting::where('variable', $remarkKey)->first() ?? new Setting();
                $s->variable = $remarkKey;
                $s->value = $this->cleanMultilangValue($request->$remarkKey, $s->value);
                $s->save();
            }

            // Schedule start/end (plain datetime strings; empty clears the schedule).
            foreach (['_start', '_end'] as $suffix) {
                $key = $toggle . $suffix;
                if ($request->has($key)) {
                    $s = Setting::where('variable', $key)->first() ?? new Setting();
                    $s->variable = $key;
                    $v = $request->$key;
                    $s->value = ($v === null || $v === 'null') ? '' : $v;
                    $s->save();
                }
            }

            // Toggle last so the broadcast carries the just-saved remark.
            if ($request->has($toggle)) {
                CommonHelper::applyMaintenance($toggle, (int) $request->$toggle);
            }
        }

        return CommonHelper::responseSuccess('maintenance_settings_saved_successfully');
    }

    /**
     * Save SMTP mail settings
     */
    public function save_smtp_mail_setting(Request $request)
    {
        $smtp_variables = [
            'mailer',
            'smtp_from_mail',
            'smtp_reply_to',
            'smtp_email_password',
            'smtp_host',
            'smtp_port',
            'smtp_content_type',
            'smtp_encryption_type'
        ];

        foreach ($request->all() as $key => $value) {
            if (in_array($key, $smtp_variables)) {
                $value = $value ?? " ";
                $setting = Setting::where('variable', $key)->first();

                if ($setting) {
                    $setting->variable = $key;
                    $setting->value = $value;
                    $setting->save();
                } else {
                    $setting = new Setting();
                    $setting->variable = $key;
                    $setting->value = $value;
                    $setting->save();
                }
            }
        }

        return CommonHelper::responseSuccess('smtp_mail_settings_saved_successfully');
    }

    /**
     * Save third party API credentials
     */
    public function save_third_party_api_setting(Request $request)
    {
        $api_variables = [
            'google_place_api_key', 'google_map_api_key', 'apiKey', 'googleMapApiKey', 'text_gen_key', 'map_provider',
            'clarity_project_id_panel', 'clarity_status_panel',
            'clarity_project_id_delivery_boy', 'clarity_status_delivery_boy',
            'clarity_project_id_web', 'clarity_status_web',
            'clarity_project_id_customer', 'clarity_status_customer',
            'cashfree_status', 'cashfree_mode', 'cashfree_app_id', 'cashfree_secret_key', 'cashfree_title',
            'cloudinary_cloud_name', 'cloudinary_api_key', 'cloudinary_api_secret',
        ];

        foreach ($request->all() as $key => $value) {
            if (in_array($key, $api_variables)) {
                $value = $value ?? " ";

                $setting = Setting::where('variable', $key)->first();

                if ($setting) {
                    $setting->variable = $key;
                    $setting->value = $value;
                    $setting->save();
                } else {
                    $setting = new Setting();
                    $setting->variable = $key;
                    $setting->value = $value;
                    $setting->save();
                }
            }
        }

        // Handle Cashfree logo file upload to Cloudinary
        if ($request->hasFile('cashfree_logo')) {
            try {
                $cloudinaryUrl = \App\Helpers\CloudinaryHelper::uploadAsset($request->file('cashfree_logo'), 'payment-gateways/cashfree');
                
                if ($cloudinaryUrl) {
                    // Save the Cloudinary URL to settings
                    $logoSetting = Setting::where('variable', 'cashfree_logo')->first();
                    if ($logoSetting) {
                        $logoSetting->value = $cloudinaryUrl;
                        $logoSetting->save();
                    } else {
                        Setting::create([
                            'variable' => 'cashfree_logo',
                            'value' => $cloudinaryUrl,
                        ]);
                    }
                }
            } catch (\Exception $e) {
                \Log::error('Cashfree logo upload to Cloudinary failed: ' . $e->getMessage());
                return CommonHelper::responseError('Logo upload to Cloudinary failed: ' . $e->getMessage());
            }
        }

        return CommonHelper::responseSuccess('third_party_api_settings_saved_successfully');
    }

    /**
     * Save Maps API settings only
     */
    public function save_maps_settings(Request $request)
    {
        $maps_variables = [
            'google_place_api_key', 'google_map_api_key', 'apiKey', 'googleMapApiKey', 'map_provider'
        ];

        foreach ($request->all() as $key => $value) {
            if (in_array($key, $maps_variables)) {
                $value = $value ?? " ";

                Setting::updateOrCreate(
                    ['variable' => $key],
                    ['value' => $value]
                );
            }
        }

        return CommonHelper::responseSuccess(__('maps_settings_saved_successfully'));
    }

    /**
     * Save Payment Gateway settings only
     */
    public function save_payment_gateway_settings(Request $request)
    {
        $payment_variables = [
            'cashfree_status', 'cashfree_mode', 'cashfree_app_id', 'cashfree_secret_key', 'cashfree_title'
        ];

        foreach ($request->all() as $key => $value) {
            if (in_array($key, $payment_variables)) {
                $value = $value ?? " ";

                Setting::updateOrCreate(
                    ['variable' => $key],
                    ['value' => $value]
                );
            }
        }

        // Handle Cashfree logo file upload
        if ($request->hasFile('cashfree_logo')) {
            try {
                // Check if Cloudinary is configured
                if (\App\Helpers\CloudinaryHelper::isConfigured()) {
                    // Upload to Cloudinary
                    $cloudinaryUrl = \App\Helpers\CloudinaryHelper::uploadAsset($request->file('cashfree_logo'), 'payment-gateways/cashfree');
                    
                    if ($cloudinaryUrl) {
                        Setting::updateOrCreate(
                            ['variable' => 'cashfree_logo'],
                            ['value' => $cloudinaryUrl]
                        );
                    }
                } else {
                    // Fallback: Save locally if Cloudinary is not configured
                    $file = $request->file('cashfree_logo');
                    $filename = 'cashfree_logo_' . time() . '.' . $file->getClientOriginalExtension();
                    $path = $file->storeAs('payment-gateways', $filename, 'public');
                    
                    Setting::updateOrCreate(
                        ['variable' => 'cashfree_logo'],
                        ['value' => $path]
                    );
                }
            } catch (\Exception $e) {
                \Log::error('Cashfree logo upload failed: ' . $e->getMessage());
                return CommonHelper::responseError(__('logo_upload_failed') . ': ' . $e->getMessage());
            }
        }

        return CommonHelper::responseSuccess(__('payment_gateway_settings_saved_successfully'));
    }

    /**
     * Save Storage settings only
     */
    public function save_storage_settings(Request $request)
    {
        $storage_variables = [
            'cloudinary_cloud_name', 'cloudinary_api_key', 'cloudinary_api_secret'
        ];

        foreach ($request->all() as $key => $value) {
            if (in_array($key, $storage_variables)) {
                $value = $value ?? " ";

                Setting::updateOrCreate(
                    ['variable' => $key],
                    ['value' => $value]
                );
            }
        }

        return CommonHelper::responseSuccess(__('storage_settings_saved_successfully'));
    }

    /** Confidential setting variables blanked in demo mode (super admin exempt). */
    private array $confidentialSettingKeys = [
        'smtp_email_password', 'apiKey', 'googleMapApiKey', 'google_map_api_key',
        'google_place_api_key', 'text_gen_key', 'android_map_key', 'ios_map_key',
        'firebase_authentication', 'reverb_app_secret', 'pusher_app_secret',
        'cashfree_app_id', 'cashfree_secret_key', 'cloudinary_api_key', 'cloudinary_api_secret',
    ];

    /** Blank confidential values on a Setting collection when demo masking applies. */
    private function maskConfidentialSettings($settings)
    {
        if (!shouldDemoMask()) {
            return $settings;
        }
        foreach ($settings as $setting) {
            if (in_array($setting->variable, $this->confidentialSettingKeys, true) && !empty($setting->value)) {
                $setting->value = '';
            }
        }
        return $settings;
    }

    public function getAboutUs()
    {
        return CommonHelper::responseWithData(Setting::where('variable', 'about_us')->first());
    }

    public function saveAboutUs(Request $request)
    {
        $this->saveMultilangPage('about_us', $request->about_us ?? '');
        return CommonHelper::responseSuccess('about_us_saved_successfully');
    }

    public function printAboutUs(Request $request)
    {
        echo $this->multilangPageContent(Setting::get_value('about_us'), $request->input('lang', 'en'));
    }

    public function getContactUs()
    {
        return CommonHelper::responseWithData(Setting::where('variable', 'contact_us')->first());
    }

    public function saveContactUs(Request $request)
    {
        $this->saveMultilangPage('contact_us', $request->contact_us ?? '');
        return CommonHelper::responseSuccess('contact_us_saved_successfully');
    }

    public function printContactUs(Request $request)
    {
        echo $this->multilangPageContent(Setting::get_value('contact_us'), $request->input('lang', 'en'));
    }

    /** Store a language-wise JSON page value (whitespace-normalised), or plain text. */
    private function saveMultilangPage(string $variable, $raw): void
    {
        $setting = Setting::where('variable', $variable)->first() ?? new Setting();
        $setting->variable = $variable;
        $setting->value = $this->cleanMultilangPage($raw);
        $setting->save();
    }

    private function cleanMultilangPage($value): string
    {
        if (empty($value)) {
            return '';
        }
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $clean = [];
            foreach ($decoded as $lang => $content) {
                $content = preg_replace('/\r\n|\r|\n/', ' ', (string) $content);
                $content = preg_replace('/\s+/', ' ', $content);
                $clean[$lang] = trim($content);
            }
            return json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return trim(preg_replace('/\s+/', ' ', (string) $value));
    }

    /** Resolve the requested language's content (falls back to the first available). */
    private function multilangPageContent($value, string $lang): string
    {
        if (empty($value)) {
            return '';
        }
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $content = $decoded[$lang] ?? '';
            if ($content === '' || $content === null) {
                $content = reset($decoded);
            }
            return str_replace(["\r", "\n"], '', (string) $content);
        }
        return str_replace(["\r", "\n"], '', (string) $value);
    }

    public function getBroadcastSetting()
    {
        $driver = config('broadcasting.default');
        if (!in_array($driver, self::BROADCAST_DRIVERS, true)) {
            $driver = 'reverb';
        }

        // Blank the app secrets in demo mode (super admin exempt).
        $mask = fn ($value) => shouldDemoMask() ? '' : (string) $value;

        return CommonHelper::responseWithData([
            'broadcast_driver' => $driver,

            'reverb_app_id'     => (string) config('broadcasting.connections.reverb.app_id'),
            'reverb_app_key'    => (string) config('broadcasting.connections.reverb.key'),
            'reverb_app_secret' => $mask(config('broadcasting.connections.reverb.secret')),
            'reverb_host'       => (string) config('broadcasting.connections.reverb.options.host'),
            'reverb_port'       => (string) config('broadcasting.connections.reverb.options.port'),
            'reverb_scheme'     => (string) config('broadcasting.connections.reverb.options.scheme'),

            'pusher_app_id'      => (string) config('broadcasting.connections.pusher.app_id'),
            'pusher_app_key'     => (string) config('broadcasting.connections.pusher.key'),
            'pusher_app_secret'  => $mask(config('broadcasting.connections.pusher.secret')),
            'pusher_app_cluster' => (string) config('broadcasting.connections.pusher.options.cluster'),
        ]);
    }

    /**
     * Save the realtime chat driver + its credentials to .env.
     */
    public function save_broadcast_setting(Request $request)
    {
        $driver = $request->input('broadcast_driver');

        $rules = ['broadcast_driver' => 'required|in:' . implode(',', self::BROADCAST_DRIVERS)];

        // Only the active driver's credentials are required — the other one may be
        // left blank while it's switched off.
        if ($driver === 'reverb') {
            $rules += [
                'reverb_app_id'     => 'required|string',
                'reverb_app_key'    => 'required|string',
                'reverb_app_secret' => 'required|string',
                'reverb_host'       => 'required|string',
                'reverb_port'       => 'required|integer|min:1|max:65535',
                'reverb_scheme'     => 'required|in:http,https',
            ];
        } elseif ($driver === 'pusher') {
            $rules += [
                'pusher_app_id'      => 'required|string',
                'pusher_app_key'     => 'required|string',
                'pusher_app_secret'  => 'required|string',
                'pusher_app_cluster' => 'required|string',
            ];
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        $env = ['BROADCAST_DRIVER' => $driver];

        // Persist whatever was supplied for BOTH drivers, so switching back later
        // doesn't lose the other driver's credentials.
        $map = [
            'reverb_app_id'      => 'REVERB_APP_ID',
            'reverb_app_key'     => 'REVERB_APP_KEY',
            'reverb_app_secret'  => 'REVERB_APP_SECRET',
            'reverb_host'        => 'REVERB_HOST',
            'reverb_port'        => 'REVERB_PORT',
            'reverb_scheme'      => 'REVERB_SCHEME',
            'pusher_app_id'      => 'PUSHER_APP_ID',
            'pusher_app_key'     => 'PUSHER_APP_KEY',
            'pusher_app_secret'  => 'PUSHER_APP_SECRET',
            'pusher_app_cluster' => 'PUSHER_APP_CLUSTER',
        ];

        foreach ($map as $field => $envKey) {
            if ($request->has($field)) {
                $env[$envKey] = (string) $request->input($field, '');
            }
        }

        if (!EnvHelper::set($env)) {
            return CommonHelper::responseError(__('env_file_is_not_writable'));
        }

        // The frontend reads these through config() in the blade, so the cached
        // config has to go or the panel keeps talking to the old driver.
        Artisan::call('config:clear');

        return CommonHelper::responseSuccess('broadcast_settings_saved_successfully');
    }

    /**
     * Save cart settings
     */
    public function save_cart_setting(Request $request)
    {
        $cart_variables = [
            'cart_notification',
            'notification_delay_after_cart_addition',
            'notification_interval',
            'notification_stop_time'
        ];

        foreach ($request->all() as $key => $value) {
            if (in_array($key, $cart_variables)) {
                $value = $value ?? " ";
                $setting = Setting::where('variable', $key)->first();

                if ($setting) {
                    $setting->variable = $key;
                    $setting->value = $value;
                    $setting->save();
                } else {
                    $setting = new Setting();
                    $setting->variable = $key;
                    $setting->value = $value;
                    $setting->save();
                }
            }
        }

        return CommonHelper::responseSuccess('cart_settings_saved_successfully');
    }

    public function system_update(Request $request)
    {
        if (shouldDemoMask()) {
            return CommonHelper::responseError('this_action_is_disabled_in_demo_mode');
        }

        $validator = Validator::make($request->all(), [
            'update_file'   => 'required|file|max:1048576',
        ]);
        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        $file = $request->file('update_file');
        if (strtolower($file->getClientOriginalExtension()) !== 'zip') {
            return CommonHelper::responseError('please_upload_a_valid_zip_file');
        }

        if (! class_exists(\ZipArchive::class)) {
            return CommonHelper::responseError('php_zip_extension_is_required');
        }

        // fixed structure  snapbuy-update-<x.y.z>.zip  — reject anything else.
        if (! preg_match('/^snapbuy-update-(\d+\.\d+\.\d+)\.zip$/i', $file->getClientOriginalName(), $m)) {
            return CommonHelper::responseError('invalid_update_package_name');
        }
        $newVersion = $m[1];

        // Must be strictly newer than the installed version (blocks re-apply/downgrade).
        $currentVersion = trim((string) @file_get_contents(base_path('version.txt'))) ?: '0.0.0';
        if (version_compare($newVersion, $currentVersion, '<=')) {
            return CommonHelper::responseError('update_version_is_not_newer');
        }

        $work = storage_path('app/system_update/' . time());
        File::ensureDirectoryExists($work);

        try {
            $file->move($work, 'update.zip');
            $zipPath = $work . '/update.zip';

            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new \RuntimeException(__('please_upload_a_valid_zip_file'));
            }
            $extract = $work . '/extracted';
            File::ensureDirectoryExists($extract);
            $zip->extractTo($extract);
            $zip->close();

            $entries = array_values(array_diff(scandir($extract), ['.', '..']));
            $source = $extract;
            if (count($entries) === 1 && is_dir($extract . '/' . $entries[0])) {
                $source = $extract . '/' . $entries[0];
            }

            Artisan::call('down');

            File::copyDirectory($source, base_path());
            Artisan::call('migrate', ['--force' => true]);
            Log::info('system_update migrate output: ' . Artisan::output());
            Artisan::call('optimize:clear');

            file_put_contents(base_path('version.txt'), $newVersion);

            return CommonHelper::responseSuccessWithData('system_updated_successfully', ['version' => $newVersion]);
        } catch (\Throwable $e) {
            Log::error('system_update failed: ' . $e->getMessage());
            return CommonHelper::responseError($e->getMessage() ?: __('something_went_wrong'));
        } finally {
            Artisan::call('up');
            File::deleteDirectory($work);
        }
    }

    public function testMail(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'mailer' => 'required',
            'email' => 'required|email',
            'host' => 'required',
            'username' => 'required',
            'password' => 'required',
            'port' => 'required',
            'encryption' => 'required',
            'support_email' => 'required',
        ]);

        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        $config = [
            'driver' => $request->mailer,
            'host' => $request->host,
            'username' => $request->username,
            'password' => $request->password,
            'port' => (int) $request->port,
            'encryption' => $request->encryption,
            'from'       => [
                'address' => $request->username,
                'name'    => $request->app_name,
            ]
        ];

        Config::set('mail', $config);

        Mail::purge($request->mailer);

        try {
            Mail::html('Email Test Successfully!', function ($message) use ($request) {
                $message->to($request->email)
                    ->subject('Testing Mail')
                    ->from($request->username, $request->app_name);
            });
            return CommonHelper::responseSuccess('test_mail_sent_successfully');
        } catch (\Exception $e) {
            return CommonHelper::responseError($e->getMessage());
        }
    }

    /*  *
     * Google Places Autocomplete API
     * Provides location suggestions based on user input
     */
    public function googlePlacesAutocomplete(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'input' => 'required|string|min:2',
            'language' => 'nullable|string|size:2',
            'types' => 'nullable|string',
            'components' => 'nullable|string',
            'sessiontoken' => 'nullable|string',
            'source' => 'required|string|in:app,web',
        ]);

        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        // Track API call
        ApiCallTracking::incrementCallCount('google_places_autocomplete', $request->source);

        try {
            // Get Google Places API key from settings
            // Try plain text copies first (apiKey, googleMapApiKey) as they are stored unencrypted
            // The encrypted versions (google_place_api_key, google_map_api_key) use CryptoJS which can't be decrypted by Laravel
            $apiKey = Setting::get_value('apiKey')
                ?? Setting::get_value('googleMapApiKey')
                ?? Setting::get_value('google_place_api_key') 
                ?? Setting::get_value('google_map_api_key');

            if (empty($apiKey)) {
                return CommonHelper::responseError('Google Places API key not configured. Please set it in Settings > Third Party API > Maps section.');
            }

            // The plain text copies (apiKey, googleMapApiKey) are already decrypted
            // No decryption needed since frontend stores these as plain text alongside the encrypted versions

            // Build the API URL
            $baseUrl = 'https://maps.googleapis.com/maps/api/place/autocomplete/json';
            $params = [
                'input' => $request->input,
                'key' => $apiKey,
                'language' => $request->language ?? 'en',
            ];

            // Add optional parameters
            if ($request->types) {
                $params['types'] = $request->types;
            }

            if ($request->components) {
                $params['components'] = $request->components;
            }

            if ($request->sessiontoken) {
                $params['sessiontoken'] = $request->sessiontoken;
            }

            // Make the request to Google Places API
            $url = $baseUrl . '?' . http_build_query($params);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            unset($ch);

            if (!empty($curlError)) {
                Log::error('Google Places API CURL Error: ' . $curlError);
                return CommonHelper::responseError('Failed to connect to Google Places API');
            }

            if ($httpCode !== 200) {
                Log::error('Google Places API HTTP Error: ' . $httpCode . ' - Response: ' . substr($response, 0, 500));
                return CommonHelper::responseError('Failed to fetch location suggestions (HTTP ' . $httpCode . ')');
            }

            $data = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Google Places API JSON Error: ' . json_last_error_msg());
                return CommonHelper::responseError('Invalid response from Google Places API');
            }

            // Check if Google API returned an error
            if (isset($data['status']) && $data['status'] !== 'OK' && $data['status'] !== 'ZERO_RESULTS') {
                $errorMessage = $data['error_message'] ?? 'Google Places API error: ' . $data['status'];
                Log::error('Google Places API Status Error: ' . $errorMessage);
                return CommonHelper::responseError($errorMessage);
            }

            // Format the response for frontend consumption
            $suggestions = [];
            if (isset($data['predictions']) && is_array($data['predictions'])) {
                foreach ($data['predictions'] as $prediction) {
                    // Extract main text and secondary text from structured_formatting
                    $mainText = '';
                    $secondaryText = '';
                    $mainTextMatches = [];

                    if (isset($prediction['structured_formatting'])) {
                        $mainText = $prediction['structured_formatting']['main_text'] ?? '';
                        $secondaryText = $prediction['structured_formatting']['secondary_text'] ?? '';

                        // Extract matches for main text
                        if (isset($prediction['structured_formatting']['main_text_matched_substrings'])) {
                            foreach ($prediction['structured_formatting']['main_text_matched_substrings'] as $match) {
                                $mainTextMatches[] = [
                                    'endOffset' => $match['offset'] + $match['length']
                                ];
                            }
                        }
                    }

                    // Extract matches for full text
                    $textMatches = [];
                    if (isset($prediction['matched_substrings'])) {
                        foreach ($prediction['matched_substrings'] as $match) {
                            $textMatches[] = [
                                'endOffset' => $match['offset'] + $match['length']
                            ];
                        }
                    }

                    $suggestions[] = [
                        'placePrediction' => [
                            'place' => 'places/' . ($prediction['place_id'] ?? ''),
                            'placeId' => $prediction['place_id'] ?? '',
                            'text' => [
                                'text' => $prediction['description'] ?? '',
                                'matches' => $textMatches
                            ],
                            'structuredFormat' => [
                                'mainText' => [
                                    'text' => $mainText,
                                    'matches' => $mainTextMatches
                                ],
                                'secondaryText' => [
                                    'text' => $secondaryText
                                ]
                            ],
                            'types' => $prediction['types'] ?? []
                        ]
                    ];
                }
            }

            Log::info('Google Places API returned ' . count($suggestions) . ' suggestions for input: ' . $request->input);

            return CommonHelper::responseWithData([
                'suggestions' => $suggestions,
                'status' => $data['status'] ?? 'OK'
            ]);
        } catch (\Exception $e) {
            Log::error('Google Places Autocomplete Error: ' . $e->getMessage());
            return CommonHelper::responseError('An error occurred while fetching location suggestions');
        }
    }

    /**
     * Google Places Details API
     * Get detailed information about a specific place
     */
    public function googlePlacesDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'place_id' => 'required|string',
            'fields' => 'nullable|string',
            'language' => 'nullable|string|size:2',
            'sessiontoken' => 'nullable|string',
            'source' => 'required|string|in:app,web',
        ]);

        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        // Track API call
        ApiCallTracking::incrementCallCount('google_places_details', $request->source);

        try {
            // Get Google Places API key from settings
            // Try plain text copies first (apiKey, googleMapApiKey) as they are stored unencrypted
            // The encrypted versions (google_place_api_key, google_map_api_key) use CryptoJS which can't be decrypted by Laravel
            $apiKey = Setting::get_value('apiKey')
                ?? Setting::get_value('googleMapApiKey')
                ?? Setting::get_value('google_place_api_key') 
                ?? Setting::get_value('google_map_api_key');

            if (empty($apiKey)) {
                return CommonHelper::responseError('Google Places API key not configured. Please set it in Settings > Third Party API > Maps section.');
            }

            // The plain text copies (apiKey, googleMapApiKey) are already decrypted
            // No decryption needed since frontend stores these as plain text alongside the encrypted versions

            // Build the API URL with comprehensive fields
            $baseUrl = 'https://maps.googleapis.com/maps/api/place/details/json';
            $params = [
                'place_id' => $request->place_id,
                'key' => $apiKey,
                'language' => $request->language ?? 'en',
                'fields' => $request->fields ?? 'name,place_id,types,formatted_address,address_components,geometry,photos,website,url,utc_offset,icon,icon_background_color,icon_mask_base_uri,price_level,rating,user_ratings_total,reviews,opening_hours,business_status,formatted_phone_number,international_phone_number,editorial_summary'
            ];

            // Add optional parameters
            if ($request->sessiontoken) {
                $params['sessiontoken'] = $request->sessiontoken;
            }

            // Make the request to Google Places API
            $url = $baseUrl . '?' . http_build_query($params);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            unset($ch);

            if (!empty($curlError)) {
                Log::error('Google Places API CURL Error: ' . $curlError);
                return CommonHelper::responseError('Failed to connect to Google Places API');
            }

            if ($httpCode !== 200) {
                Log::error('Google Places Details HTTP Error: ' . $httpCode . ' - Response: ' . substr($response, 0, 500));
                return CommonHelper::responseError('Failed to fetch place details (HTTP ' . $httpCode . ')');
            }

            $data = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Google Places Details JSON Error: ' . json_last_error_msg());
                return CommonHelper::responseError('Invalid response from Google Places API');
            }

            // Check if Google API returned an error
            if (isset($data['status']) && $data['status'] !== 'OK') {
                $errorMessage = $data['error_message'] ?? 'Google Places API error: ' . $data['status'];
                Log::error('Google Places Details Status Error: ' . $errorMessage);
                return CommonHelper::responseError($errorMessage);
            }

            // Transform the response to match the desired format
            $result = $data['result'] ?? [];

            $formattedResponse = [
                'name' => 'places/' . ($result['place_id'] ?? ''),
                'id' => $result['place_id'] ?? '',
                'types' => $result['types'] ?? [],
                'formattedAddress' => $result['formatted_address'] ?? '',
                'addressComponents' => [],
                'location' => [
                    'latitude' => $result['geometry']['location']['lat'] ?? 0,
                    'longitude' => $result['geometry']['location']['lng'] ?? 0
                ],
                'viewport' => [
                    'low' => [
                        'latitude' => $result['geometry']['viewport']['southwest']['lat'] ?? 0,
                        'longitude' => $result['geometry']['viewport']['southwest']['lng'] ?? 0
                    ],
                    'high' => [
                        'latitude' => $result['geometry']['viewport']['northeast']['lat'] ?? 0,
                        'longitude' => $result['geometry']['viewport']['northeast']['lng'] ?? 0
                    ]
                ],
                'googleMapsUri' => $result['url'] ?? '',
                'websiteUri' => $result['website'] ?? '',
                'utcOffsetMinutes' => $result['utc_offset'] ?? 0,
                'adrFormatAddress' => $this->generateAdrFormatAddress($result['address_components'] ?? []),
                'iconMaskBaseUri' => $result['icon_mask_base_uri'] ?? '',
                'iconBackgroundColor' => $result['icon_background_color'] ?? '',
                'displayName' => [
                    'text' => $result['name'] ?? '',
                    'languageCode' => $request->language ?? 'en'
                ],
                'shortFormattedAddress' => $this->getShortFormattedAddress($result['address_components'] ?? []),
                'photos' => [],
                'pureServiceAreaBusiness' => false,
                'googleMapsLinks' => [
                    'directionsUri' => $result['url'] ?? '',
                    'placeUri' => $result['url'] ?? '',
                    'photosUri' => $result['url'] ?? ''
                ],
            ];

            // Process address components
            if (isset($result['address_components']) && is_array($result['address_components'])) {
                foreach ($result['address_components'] as $component) {
                    $formattedResponse['addressComponents'][] = [
                        'longText' => $component['long_name'] ?? '',
                        'shortText' => $component['short_name'] ?? '',
                        'types' => $component['types'] ?? [],
                        'languageCode' => $request->language ?? 'en'
                    ];
                }
            }

            // Process photos
            if (isset($result['photos']) && is_array($result['photos'])) {
                foreach ($result['photos'] as $photo) {
                    $formattedResponse['photos'][] = [
                        'name' => 'places/' . ($result['place_id'] ?? '') . '/photos/' . ($photo['photo_reference'] ?? ''),
                        'widthPx' => $photo['width'] ?? 0,
                        'heightPx' => $photo['height'] ?? 0,
                        'authorAttributions' => [
                            [
                                'displayName' => $photo['html_attributions'][0] ?? 'Unknown',
                                'uri' => '',
                                'photoUri' => ''
                            ]
                        ],
                        'flagContentUri' => '',
                        'googleMapsUri' => ''
                    ];
                }
            }

            Log::info('Google Places Details successfully retrieved for place: ' . $request->place_id);

            return CommonHelper::responseWithData($formattedResponse);
        } catch (\Exception $e) {
            Log::error('Google Places Details Error: ' . $e->getMessage());
            return CommonHelper::responseError('An error occurred while fetching place details');
        }
    }

    /**
     * Generate ADR format address from address components
     */
    private function generateAdrFormatAddress($addressComponents)
    {
        $adrParts = [];

        foreach ($addressComponents as $component) {
            $types = $component['types'] ?? [];
            $longName = $component['long_name'] ?? '';

            if (in_array('locality', $types)) {
                $adrParts[] = '<span class="locality">' . $longName . '</span>';
            } elseif (in_array('administrative_area_level_1', $types)) {
                $adrParts[] = '<span class="region">' . $longName . '</span>';
            } elseif (in_array('country', $types)) {
                $adrParts[] = '<span class="country-name">' . $longName . '</span>';
            }
        }

        return implode(', ', $adrParts);
    }

    /**
     * Get short formatted address (usually just the locality)
     */
    private function getShortFormattedAddress($addressComponents)
    {
        foreach ($addressComponents as $component) {
            $types = $component['types'] ?? [];
            if (in_array('locality', $types)) {
                return $component['long_name'] ?? '';
            }
        }
        return '';
    }

    /**
     * Google Maps Geocoding API
     * Convert coordinates to address with fixed radius of 500
     */
    public function googleMapsGeocoding(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'source' => 'required|string|in:app,web',
        ]);

        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        // Track API call
        ApiCallTracking::incrementCallCount('google_maps_geocoding', $request->source);

        try {
            // Get Google Places API key from settings
            $apiKey = Setting::get_value('apiKey');

            if (empty($apiKey)) {
                return CommonHelper::responseError('Google Maps API key not configured');
            }

            // Build the API URL with fixed radius of 500
            $baseUrl = 'https://maps.googleapis.com/maps/api/geocode/json';
            $params = [
                'key' => $apiKey,
                'latlng' => $request->latitude . ',' . $request->longitude,
                'radius' => 500,
            ];

            // Make the request to Google Maps Geocoding API
            $url = $baseUrl . '?' . http_build_query($params);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; snapbuy/1.0)');

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            unset($ch);

            if ($curlError) {
                return CommonHelper::responseError('Network error: ' . $curlError);
            }

            if ($httpCode !== 200) {
                return CommonHelper::responseError('Failed to fetch geocoding data. HTTP Code: ' . $httpCode);
            }

            if (empty($response)) {
                return CommonHelper::responseError('Empty response from Google Maps API');
            }

            $data = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return CommonHelper::responseError('Invalid response from Google Maps Geocoding API: ' . json_last_error_msg());
            }

            // Check if Google API returned an error
            if (isset($data['status']) && $data['status'] !== 'OK' && $data['status'] !== 'ZERO_RESULTS') {
                $errorMessage = $data['error_message'] ?? 'Google Maps Geocoding API error: ' . $data['status'];
                return CommonHelper::responseError($errorMessage);
            }

            // Format the response for better consumption
            $formattedResults = [];
            if (isset($data['results']) && is_array($data['results'])) {
                foreach ($data['results'] as $result) {
                    $formattedResults[] = [
                        'formatted_address' => $result['formatted_address'] ?? '',
                        'geometry' => $result['geometry'] ?? [],
                        'place_id' => $result['place_id'] ?? '',
                        'types' => $result['types'] ?? [],
                        'address_components' => $result['address_components'] ?? [],
                        'partial_match' => $result['partial_match'] ?? false,
                    ];
                }
            }

            return CommonHelper::responseWithData([
                'results' => $formattedResults,
                'status' => $data['status'] ?? 'OK',
                'radius' => 500
            ]);
        } catch (\Exception $e) {
            Log::error('Google Maps Geocoding Error: ' . $e->getMessage());
            return CommonHelper::responseError('An error occurred while processing geocoding request: ' . $e->getMessage());
        }
    }

    /**
     * Google Gemini AI API
     * Generate content using Google's Gemini AI model
     */
    public function googleGeminiAI(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'prompt' => 'required|string|min:1|max:10000',
            'source' => 'required|string|in:app,web',
        ]);

        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        // Track API call
        ApiCallTracking::incrementCallCount('google_gemini', $request->source);

        try {
            // Get Google Gemini API key from settings
            $apiKey = Setting::get_value('text_gen_key');

            if (empty($apiKey)) {
                return CommonHelper::responseError('Google Gemini API key not configured');
            }

            // Build the API URL
            $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';
            $url = $baseUrl . '?key=' . $apiKey;

            // Prepare the request payload - simplified format
            $payload = [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => $request->prompt
                            ]
                        ]
                    ]
                ]
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json'
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            unset($ch);
            // Log::info($response);
            if ($curlError) {
                return CommonHelper::responseError('Network error: ' . $curlError);
            }

            if ($httpCode !== 200) {
                return CommonHelper::responseError('Failed to generate content. HTTP Code: ' . $httpCode);
            }

            if (empty($response)) {
                return CommonHelper::responseError('Empty response from Google Gemini API');
            }

            $data = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return CommonHelper::responseError('Invalid response from Google Gemini API: ' . json_last_error_msg());
            }

            // Check if Gemini API returned an error
            if (isset($data['error'])) {
                $errorMessage = $data['error']['message'] ?? 'Google Gemini API error';
                return CommonHelper::responseError('Gemini API Error: ' . $errorMessage);
            }

            // Extract only the generated content - simplified response
            $generatedContent = '';
            if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                $generatedContent = $data['candidates'][0]['content']['parts'][0]['text'];
            } else {
                return CommonHelper::responseError('No content generated by Gemini AI');
            }

            // Return only the essential data
            return CommonHelper::responseWithData($generatedContent);
        } catch (\Exception $e) {
            Log::error('Google Gemini AI Error: ' . $e->getMessage());
            return CommonHelper::responseError('An error occurred while generating content: ' . $e->getMessage());
        }
    }

    // ============================================================
    // Map provider dispatchers (google paid vs OSM free).
    // Frontend / app call these instead of the google_* endpoints —
    // each one routes to the configured provider and returns a
    // normalized response shape so callers do not branch.
    // ============================================================

    protected function activeMapProvider(): string
    {
        $provider = Setting::get_value('map_provider');
        return $provider === 'google' ? 'google' : 'osm';
    }

    public function placesAutocomplete(Request $request)
    {
        return $this->activeMapProvider() === 'google'
            ? $this->googlePlacesAutocomplete($request)
            : $this->osmPlacesAutocomplete($request);
    }

    public function placesDetails(Request $request)
    {
        return $this->activeMapProvider() === 'google'
            ? $this->googlePlacesDetails($request)
            : $this->osmPlacesDetails($request);
    }

    public function mapsGeocoding(Request $request)
    {
        return $this->activeMapProvider() === 'google'
            ? $this->googleMapsGeocoding($request)
            : $this->osmReverseGeocoding($request);
    }

    /**
     * Nominatim search → normalized to the Google-Places-Autocomplete shape
     * so frontend code can stay provider-agnostic.
     */
    public function osmPlacesAutocomplete(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'input'    => 'required|string|min:2',
            'language' => 'nullable|string|size:2',
            'source'   => 'required|string|in:app,web',
        ]);
        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        ApiCallTracking::incrementCallCount('osm_places_autocomplete', $request->source);

        try {
            $params = [
                'q'              => $request->input,
                'format'         => 'json',
                'addressdetails' => 1,
                'limit'          => 5,
                'accept-language' => $request->language ?? 'en',
            ];
            $data = $this->nominatimRequest('search', $params);
            if (isset($data['__error'])) {
                return CommonHelper::responseError($data['__error']);
            }

            $suggestions = [];
            foreach ((array) $data as $row) {
                $osmType = $row['osm_type'] ?? '';
                $osmId   = $row['osm_id'] ?? '';
                // Encode a placeId callers can pass back to /places_details.
                $placeId = $osmType && $osmId ? (strtoupper(substr($osmType, 0, 1)) . $osmId) : '';
                $display = $row['display_name'] ?? '';
                $address = $row['address'] ?? [];
                $mainText = $address['city'] ?? $address['town'] ?? $address['village']
                    ?? $address['suburb'] ?? $address['road'] ?? $display;
                $secondary = trim(str_replace($mainText, '', $display), " ,");

                $suggestions[] = [
                    'placePrediction' => [
                        'place'    => 'osm/' . $placeId,
                        'placeId'  => $placeId,
                        'text'     => ['text' => $display, 'matches' => []],
                        'structuredFormat' => [
                            'mainText'      => ['text' => $mainText, 'matches' => []],
                            'secondaryText' => ['text' => $secondary],
                        ],
                        'types' => [$row['type'] ?? $row['class'] ?? 'place'],
                        // Provider-specific extras — frontend may use as fast path
                        // and skip the details round-trip if it wants.
                        '_osm' => [
                            'lat'     => isset($row['lat']) ? (float) $row['lat'] : null,
                            'lon'     => isset($row['lon']) ? (float) $row['lon'] : null,
                            'address' => $address,
                        ],
                    ],
                ];
            }

            return CommonHelper::responseWithData([
                'suggestions' => $suggestions,
                'status'      => 'OK',
                'provider'    => 'osm',
            ]);
        } catch (\Exception $e) {
            Log::error('OSM Places Autocomplete Error: ' . $e->getMessage());
            return CommonHelper::responseError('An error occurred while fetching location suggestions');
        }
    }

    /**
     * Nominatim lookup by encoded place_id (e.g. "N12345", "W67890", "R11").
     * Normalized to the Google-Places-Details shape.
     */
    public function osmPlacesDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'place_id' => 'required|string',
            'language' => 'nullable|string|size:2',
            'source'   => 'required|string|in:app,web',
        ]);
        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        ApiCallTracking::incrementCallCount('osm_places_details', $request->source);

        try {
            $placeId = (string) $request->place_id;
            // Expected forms: "N123", "W123", "R123" (T = node/way/relation prefix).
            if (!preg_match('/^[NWR]\d+$/i', $placeId)) {
                return CommonHelper::responseError('Invalid OSM place id');
            }
            $params = [
                'osm_ids'        => strtoupper($placeId),
                'format'         => 'json',
                'addressdetails' => 1,
                'accept-language' => $request->language ?? 'en',
            ];
            $data = $this->nominatimRequest('lookup', $params);
            if (isset($data['__error'])) {
                return CommonHelper::responseError($data['__error']);
            }
            $row = is_array($data) && !empty($data) ? $data[0] : null;
            if (!$row) {
                return CommonHelper::responseError('Place not found');
            }
            return CommonHelper::responseWithData($this->normalizeOsmPlace($row, $request->language ?? 'en'));
        } catch (\Exception $e) {
            Log::error('OSM Places Details Error: ' . $e->getMessage());
            return CommonHelper::responseError('An error occurred while fetching place details');
        }
    }

    /**
     * Nominatim reverse geocoding → shape matching googleMapsGeocoding().
     */
    public function osmReverseGeocoding(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'latitude'  => 'required|numeric',
            'longitude' => 'required|numeric',
            'source'    => 'required|string|in:app,web',
        ]);
        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        ApiCallTracking::incrementCallCount('osm_maps_geocoding', $request->source);

        try {
            $params = [
                'lat'            => $request->latitude,
                'lon'            => $request->longitude,
                'format'         => 'json',
                'addressdetails' => 1,
                'zoom'           => 18,
            ];
            $data = $this->nominatimRequest('reverse', $params);
            if (isset($data['__error'])) {
                return CommonHelper::responseError($data['__error']);
            }
            if (!is_array($data) || empty($data)) {
                return CommonHelper::responseWithData(['results' => [], 'status' => 'ZERO_RESULTS']);
            }

            $components = $this->osmAddressToComponents($data['address'] ?? []);
            $result = [
                'formatted_address'  => $data['display_name'] ?? '',
                'geometry'           => [
                    'location' => [
                        'lat' => isset($data['lat']) ? (float) $data['lat'] : 0,
                        'lng' => isset($data['lon']) ? (float) $data['lon'] : 0,
                    ],
                ],
                'place_id'           => ($data['osm_type'] ?? '') && ($data['osm_id'] ?? '')
                    ? strtoupper(substr($data['osm_type'], 0, 1)) . $data['osm_id']
                    : '',
                'types'              => [$data['type'] ?? 'place'],
                'address_components' => array_map(function ($c) {
                    return [
                        'long_name'  => $c['longText'],
                        'short_name' => $c['shortText'],
                        'types'      => $c['types'],
                    ];
                }, $components),
                'partial_match'      => false,
            ];

            return CommonHelper::responseWithData([
                'results'  => [$result],
                'status'   => 'OK',
                'provider' => 'osm',
            ]);
        } catch (\Exception $e) {
            Log::error('OSM Reverse Geocoding Error: ' . $e->getMessage());
            return CommonHelper::responseError('An error occurred while processing geocoding request');
        }
    }

    protected function nominatimRequest(string $endpoint, array $params): array
    {
        $url = 'https://nominatim.openstreetmap.org/' . $endpoint . '?' . http_build_query($params);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        // Nominatim ToS requires a real User-Agent identifying the app.
        curl_setopt($ch, CURLOPT_USERAGENT, 'snapBuy/1.0 (' . parse_url(env('APP_URL', 'localhost'), PHP_URL_HOST) . ')');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        unset($ch);

        if ($err) {
            return ['__error' => 'Network error: ' . $err];
        }
        if ($httpCode !== 200) {
            return ['__error' => 'OSM request failed. HTTP ' . $httpCode];
        }
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['__error' => 'Invalid response from OSM Nominatim'];
        }
        return $data;
    }

    protected function normalizeOsmPlace(array $row, string $language = 'en'): array
    {
        $addr = $row['address'] ?? [];
        $components = $this->osmAddressToComponents($addr);
        $lat = isset($row['lat']) ? (float) $row['lat'] : 0;
        $lon = isset($row['lon']) ? (float) $row['lon'] : 0;
        $placeId = ($row['osm_type'] ?? '') && ($row['osm_id'] ?? '')
            ? strtoupper(substr($row['osm_type'], 0, 1)) . $row['osm_id']
            : '';

        return [
            'name'                  => 'osm/' . $placeId,
            'id'                    => $placeId,
            'types'                 => [$row['type'] ?? $row['class'] ?? 'place'],
            'formattedAddress'      => $row['display_name'] ?? '',
            'addressComponents'     => array_map(function ($c) use ($language) {
                return $c + ['languageCode' => $language];
            }, $components),
            'location'              => ['latitude' => $lat, 'longitude' => $lon],
            'viewport'              => [
                'low'  => ['latitude' => $lat, 'longitude' => $lon],
                'high' => ['latitude' => $lat, 'longitude' => $lon],
            ],
            'googleMapsUri'         => '',
            'websiteUri'            => '',
            'utcOffsetMinutes'      => 0,
            'adrFormatAddress'      => '',
            'iconMaskBaseUri'       => '',
            'iconBackgroundColor'   => '',
            'displayName'           => [
                'text'         => $addr['city'] ?? $addr['town'] ?? $addr['village'] ?? ($row['name'] ?? $row['display_name'] ?? ''),
                'languageCode' => $language,
            ],
            'shortFormattedAddress' => $addr['city'] ?? $addr['town'] ?? $addr['village'] ?? '',
            'photos'                => [],
            'pureServiceAreaBusiness' => false,
            'googleMapsLinks'       => [
                'directionsUri' => '',
                'placeUri'      => '',
                'photosUri'     => '',
            ],
            'provider'              => 'osm',
        ];
    }

    /**
     * Map Nominatim address keys → Google address_components-style rows.
     */
    protected function osmAddressToComponents(array $addr): array
    {
        $map = [
            'house_number' => ['street_number'],
            'road'         => ['route'],
            'suburb'       => ['sublocality', 'sublocality_level_1'],
            'neighbourhood' => ['neighborhood'],
            'village'      => ['locality'],
            'town'         => ['locality'],
            'city'         => ['locality'],
            'county'       => ['administrative_area_level_2'],
            'state'        => ['administrative_area_level_1'],
            'state_district' => ['administrative_area_level_2'],
            'postcode'     => ['postal_code'],
            'country'      => ['country'],
            'country_code' => ['country'],
        ];
        $out = [];
        foreach ($map as $key => $types) {
            if (!empty($addr[$key])) {
                $val = $addr[$key];
                $shortVal = ($key === 'country_code') ? strtoupper((string) $val) : (string) $val;
                $longVal = ($key === 'country_code') ? ($addr['country'] ?? strtoupper((string) $val)) : (string) $val;
                $out[] = ['longText' => $longVal, 'shortText' => $shortVal, 'types' => $types];
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------------
     | Website settings (merged from the old WebSettingsApiController).
     | Named webSettingsIndex/saveWebSettings so they don't collide with
     | this controller's own index()/save().
     ------------------------------------------------------------------ */

    public function webSettingsIndex()
    {
        $settingsArray = array(
            "site_title" => "",
            "website_url" => "",
            "light_mode_color" => "#0E9623",
            "dark_mode_color" => "#C8E5D5",

            "app_title" => "",
            "app_tagline" => "",
            "app_short_description" => "",

            "is_android_app" => 0,
            "android_app_url" => "",
            "play_store_logo" => "",

            "is_ios_app" => 0,
            "ios_app_url" => "",
            "ios_store_logo" => "",

            "copyright_details" => "",

            "common_meta_title" => "",
            "common_meta_description" => "",

            "cookie_consent_enabled" => 0,
            "cookie_consent_title" => "",
            "cookie_consent_description" => "",

            "favicon" => "",
            "web_logo" => "",
            "placeholder_image" => "",
            "app_download_image" => ""

        );
        $variables = array_keys($settingsArray);

        $settings = Setting::whereIn('variable', $variables)->get();

        $data = array(
            "settingsObject" => $settingsArray,
            "settings" => $settings
        );
        return CommonHelper::responseWithData($data);
    }
    public function saveWebSettings(Request $request)
    {
        $translatable = [
            'site_title',
            'common_meta_title',
            'common_meta_description',
            'app_title',
            'app_short_description',
            'cookie_consent_title',
            'cookie_consent_description',
        ];

        $validator = Validator::make($request->all(), [
            'site_title' . config('app.default_language') => 'required|string',
            'common_meta_title' . config('app.default_language') => 'required|string',
            'common_meta_description' . config('app.default_language') => 'required|string',
            'website_url' => 'required|url',
            'light_mode_color' => 'required',
            'dark_mode_color' => 'required',
            'android_app_url' => ['required_if:is_android_app,1'],
            'play_store_logo' => $request->hasFile('play_store_logo') ? 'mimes:jpeg,jpg,png,gif,svg' : '',
            'ios_app_url' => ['required_if:is_ios_app,1'],
            'ios_store_logo' => $request->hasFile('ios_store_logo') ? 'mimes:jpeg,jpg,png,gif,svg' : '',

            'favicon' => $request->hasFile('favicon') ? 'mimes:jpeg,jpg,png,gif,svg' : '',
            'web_logo' => $request->hasFile('web_logo') ? 'mimes:jpeg,jpg,png,gif,svg' : '',
            'placeholder_image' => $request->hasFile('placeholder_image') ? 'mimes:jpeg,jpg,png,gif,svg' : '',
        ]);
        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        $filePaths = array();

        // Store each uploaded front-end asset and delete the previously stored file.
        $webFileFields = ['play_store_logo', 'ios_store_logo', 'favicon', 'web_logo', 'placeholder_image', 'app_download_image'];
        foreach ($webFileFields as $field) {
            if ($request->hasFile($field)) {
                $filePaths[$field] = CommonHelper::uploadFile($request, $field, 'front_end/' . $field, Setting::get_value($field));
            }
        }
        foreach ($request->all() as $key => $value) {

            $is_file_selected = false;

            if (
                in_array($key, ['play_store_logo', 'ios_store_logo', 'favicon', 'web_logo', 'placeholder_image', 'app_download_image'])
                && isset($filePaths[$key])
            ) {
                $is_file_selected = true;
                $value = $filePaths[$key];
            }

            $setting = Setting::firstOrNew(['variable' => $key]);

            if (in_array($key, $translatable)) {

                $value = $this->cleanMultilangValue(
                    $request->$key,
                    $setting->value
                );

                $setting->value = $value !== '' ? $value : json_encode([]);
            } else {

                $setting->value = $value ?? "";
            }

            $setting->save();
        }

        return CommonHelper::responseSuccess('web_settings_saved_successfully');
    }
}
