<?php

namespace App\Http\Controllers\API;

use App\Helpers\CommonHelper;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\DeliveryBoy;
use App\Models\Role;
use App\Models\AdminToken;
use App\Services\LanguageService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AdminAuthController extends Controller
{
    public function login(Request $request)
    {
        $requestData = $request->all();
        $validator = Validator::make($requestData, [
            'email' => 'email|required',
            'password' => 'required'
        ]);

        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        if ($request->type == 3) {
            $user = Admin::with('deliveryBoy')->where('email', request()->email)->first();
            if (!$user || !$user->deliveryBoy) {
                return CommonHelper::responseError('user_is_not_register_with_this_email_address');
            }
        } else {
            $user = Admin::where('email', request()->email)->first();
            if (!$user) {
                return CommonHelper::responseError('user_is_not_register_with_this_email_address');
            }
        }

        if (!Hash::check(request()->password, $user->password)) {
            return CommonHelper::responseError('email_password_is_wrong');
        }

        // Panel separation: the Store Login accepts ONLY store users, and the main
        // admin login rejects store users (they must use the Store Login).
        $panel = $request->input('type');
        if ($panel === 'store') {
            if (!$user->isStoreUser()) {
                return CommonHelper::responseError('user_is_not_register_with_this_email_address');
            }
        } elseif ($panel != 3) {
            if ($user->isStoreUser()) {
                return CommonHelper::responseError('please_login_from_the_store_login_page');
            }
        }

        if ($user->role_id == Role::$roleDeliveryBoy && isset($user->deliveryBoy) && $user->deliveryBoy->status == DeliveryBoy::$statusRegistered) {
            return CommonHelper::responseError('your_request_under_review_you_will_get_notification_after_get_approval');
        }

        if ($user->role_id == Role::$roleDeliveryBoy && isset($user->deliveryBoy) && $user->deliveryBoy->status == DeliveryBoy::$statusRejected) {
            $data["status"] = $user->deliveryBoy->status;
            $data["remark"] = $user->deliveryBoy->remark ?? "";
            return CommonHelper::responseErrorWithData('your_request_rejected_please_contact_to_administrator_for_approval', $data);
        }

        if ($user->role_id == Role::$roleDeliveryBoy && isset($user->deliveryBoy) && $user->deliveryBoy->status == DeliveryBoy::$statusBlocked) {
            return CommonHelper::responseError('your_account_is_blocked_please_contact_to_administrator_for_activate');
        }

        // Store-panel users are disabled when their store is deactivated/deleted.
        if ($user->isStoreUser()) {
            if ((int) $user->status !== 1) {
                return CommonHelper::responseError('your_account_is_deactivated_please_contact_administrator');
            }
            // Login is refused while the store itself is inactive (or removed).
            if (!$user->store || (int) $user->store->status !== 1) {
                return CommonHelper::responseError('your_store_is_inactive_or_unavailable');
            }
        }

        Auth::login($user, false);

        if (isset($request->fcm_token) && !empty($request->fcm_token)) {
            $type = "";
            $user_id = $user->id;

            if ($user->role_id == Role::$roleDeliveryBoy) {
                $type = Role::$roleNameDeliveryBoy;
            } elseif ($user->role_id == Role::$roleAdmin) {
                $type = Role::$roleNameAdmin;
            } elseif ($user->role_id == Role::$roleSuperAdmin) {
                $type = Role::$roleNameSuperAdmin;
            } elseif ($user->isStoreUser()) {
                $type = Role::$roleNameStore;
            }

            // Store the selected language: prefer explicit id, else resolve from the code.
            $language_id = $request->input('language_id');
            if (empty($language_id) && $request->filled('language_code')) {
                $lang = app(LanguageService::class)->getLanguageByCode($request->language_code);
                $language_id = $lang ? $lang->id : null;
            }
            $language_id = $language_id ?: CommonHelper::getDefaultLanguageId();

            AdminToken::updateOrCreate(
                ['user_id' => $user_id, 'fcm_token' => $request->fcm_token],
                ['type' => $type, 'platform' => $request->platform ?? 'web', 'language_id' => $language_id]
            );
        }

        $accessToken = $user->createToken('authToken')->accessToken;

        if ($user->role_id == Role::$roleDeliveryBoy && $user->deliveryBoy) {
            $deliveryBoy = $user->deliveryBoy;
            $data = $deliveryBoy->makeHidden(['admin', 'translations'])->toArray();
            $data['email'] = $user->email;
            $data['username'] = $user->username;
            $data['allPermissions'] = $user->allPermissions;
            $data['access_token'] = $accessToken;
            return CommonHelper::responseWithData($data, false);
        }

        // Store users carry their store (with zone) so the panel can auto-scope
        // without a zone picker.
        if ($user->isStoreUser()) {
            $user->load(['store:id,name,zone_id', 'store.zone:id,country_id']);
        }

        $userArray = $user->toArray();
        $res = ['user' => $userArray, 'access_token' => $accessToken];
        return CommonHelper::responseWithData($res);
    }

    public function logout(Request $request)
    {
        $token = $request->user()->token();
        if($token){
            $token->revoke();
        }
        if (isset($request->fcm_token)) {
            AdminToken::where('user_id', $request->user()->id)
                ->where('fcm_token', $request->fcm_token)
                ->delete();
        }

        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            // Keep the chosen language across logout — only the auth session is cleared.
            $lang = $request->session()->get('lang');
            $appLocale = $request->session()->get('app_locale');
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            if ($lang !== null) {
                $request->session()->put('lang', $lang);
            }
            if ($appLocale !== null) {
                $request->session()->put('app_locale', $appLocale);
            }
        }
        return CommonHelper::responseSuccess(__('you_have_been_successfully_logged_out'));
    }

    public function forgetPassword(Request $request)
    {

        $requestData = $request->all();
        $validator = Validator::make($requestData, [
            'email' => 'required|email|exists:admins',
        ], [
            'email.exists' => "This Email is not registered, Please enter valid Email Address"
        ]);

        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        $token = time() . Str::random(30);

        DB::table('password_resets')->insert([
            'email' => $request->email,
            'token' => $token,
            'created_at' => Carbon::now()
        ]);

        CommonHelper::sendMailByTemplate($request->email, 'forgot_password_admin', [
            'reset_link' => url('/reset-password?token=' . $token),
        ]);

        return CommonHelper::responseSuccess('we_have_e_mailed_your_password_reset_link');
    }

    public function resetPassword(Request $request)
    {

        $requestData = $request->all();
        $validator = Validator::make($requestData, [
            'password' => 'required|string|confirmed',
            'password_confirmation' => 'required'
        ]);

        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        if (($pwErr = CommonHelper::validatePasswordPolicy($request->password)) !== null) {
            return CommonHelper::responseError($pwErr);
        }

        $updatePassword = DB::table('password_resets')
            ->where(['token' => $request->token])
            ->first();

        if (!$updatePassword) {
            return CommonHelper::responseError('invalid_token');
        }

        Admin::where('email', $updatePassword->email)->update(['password' => bcrypt($request->password)]);

        DB::table('password_resets')->where(['email' => $updatePassword->email])->delete();

        return CommonHelper::responseSuccess('password_updated_successfully');
    }

    /*Delivery Boy*/
    public function deliveryBoyRegister(Request $request)
    {

        $requestData = $request->all();
        $validator = Validator::make($requestData, [
            'name' => 'required',
            'email' => 'email|required|unique:admins',
            'mobile' => 'required',
            'dob' => 'required',
            'password' => 'required_with:confirm_password|same:confirm_password',
            'bonus_type' => 'required',
            'bonus_percentage' => $request->bonus_type == 1 ? 'required' : '',
            'return_bonus_type' => 'required',
            'return_bonus_percentage' => $request->return_bonus_type == 1 ? 'required' : '',
            'country_id' => 'required|integer|exists:countries,id',
            'zone_id' => 'required|integer|exists:zones,id',
            'country_code' => 'required|string',
            'driving_license' => 'required|mimes:jpeg,jpg,png,gif,pdf',
            'national_identity_card' => 'required|mimes:jpeg,jpg,png,gif,pdf',
            'profile' => 'nullable|mimes:jpeg,jpg,png,gif,webp',
        ], [
            'email.unique' => 'The :attribute has already been taken.',
        ]);

        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        // Mobile length must match the selected country's configured bounds.
        if (($mobErr = CommonHelper::validateMobileForCountry($request->country_id, $request->mobile)) !== null) {
            return CommonHelper::responseError($mobErr);
        }

        if (($pwErr = CommonHelper::validatePasswordPolicy($request->password)) !== null) {
            return CommonHelper::responseError($pwErr);
        }

        DB::beginTransaction();
        try {
            $data = array();
            $data['username'] = $request->name;
            $data['email'] = $request->email;
            $data['password'] = bcrypt($request->password);
            $data['role_id'] = Role::$roleDeliveryBoy;
            $data['created_by'] = 0;
            $admin = Admin::create($data);

            $deliveryBoy = new DeliveryBoy();
            $deliveryBoy->admin_id = $admin->id;
            $deliveryBoy->country_id = (int) $request->country_id;
            $deliveryBoy->zone_id = (int) $request->zone_id;
            $deliveryBoy->name = $request->name;
            if ($request->hasFile('profile')) {
                $deliveryBoy->profile = CommonHelper::uploadFile($request, 'profile', 'delivery_boy/profile');
            }
            $deliveryBoy->country_code = $request->country_code;
            $deliveryBoy->mobile = $request->mobile;
            $deliveryBoy->address = '';
            $deliveryBoy->dob = $request->dob;
            $deliveryBoy->bonus_type = $request->bonus_type;
            $deliveryBoy->bonus_percentage =  $request->bonus_percentage ?? 0;
            $deliveryBoy->return_bonus_type = $request->return_bonus_type ?? 0;
            $deliveryBoy->return_bonus_percentage = $request->return_bonus_percentage ?? 0;

            $deliveryBoy->status = DeliveryBoy::$statusRegistered;

            $deliveryBoy->driving_license = CommonHelper::uploadFile($request, 'driving_license', 'delivery_boy/driving_license') ?? '';
            $deliveryBoy->national_identity_card = CommonHelper::uploadFile($request, 'national_identity_card', 'delivery_boy/national_identity_card') ?? '';
            $deliveryBoy->save();


            try {
                CommonHelper::sendDeliveryBoyStatusNotification($deliveryBoy);
            } catch (\Exception $e) {
                Log::error("Register delivery_boy status notification error", [$e->getMessage()]);
            }

            DB::commit();
            return CommonHelper::responseSuccess('delivery_boy_registration_successful');
        } catch (\Exception $e) {
            Log::info("Delivery Boy Register Error : ", [$e->getMessage()]);
            DB::rollBack();
            return CommonHelper::responseError('something_went_wrong');
        }
    }

    public function validateLogin()
    {
        $login = validateLogin();
        if (isset($login['is_login']) && $login['is_login'] == true) {
            return CommonHelper::responseSuccess('success');
        }
        return CommonHelper::responseError('success');
    }

    public function addFcmToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'fcm_token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        $user = $request->user('api');
        $user_id = $user ? $user->id : 0;
        $type = $this->getAdminRoleType($user);
        $language_id = $request->input('language_id') ?: CommonHelper::getDefaultLanguageId();

        $token = AdminToken::where('fcm_token', $request->fcm_token)->first();

        if ($token) {
            if ($token->user_id == 0 && $user_id != 0) {
                $token->user_id = $user_id;
                $token->type = $type;
                $token->platform = $request->platform ?? 'android';
                $token->language_id = $language_id;
                $token->save();
                return CommonHelper::responseSuccess(__('token_updated_successfully'));
            }
            if ($request->has('language_id')) {
                $token->language_id = $language_id;
                $token->save();
            }
            return CommonHelper::responseSuccess(__('token_already_exists'));
        }

        AdminToken::create([
            'user_id' => $user_id,
            'type' => $type,
            'fcm_token' => $request->fcm_token,
            'platform' => $request->platform ?? 'android',
            'language_id' => $language_id,
        ]);

        return CommonHelper::responseSuccess(__('token_added_successfully'));
    }

    public function updateFcmToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'fcm_token' => 'required',
        ]);

        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        $user = $request->user('api');
        $user_id = $user ? $user->id : 0;
        $type = $this->getAdminRoleType($user);

        $token = AdminToken::where('fcm_token', $request->fcm_token)->first();
        $language_id = $request->input('language_id') ?: CommonHelper::getDefaultLanguageId();

        if ($token && ($token->user_id == 0 || $token->user_id == '')) {
            $token->user_id = $user_id;
            $token->type = $type;
            $token->platform = $request->platform ?? 'android';
            $token->language_id = $language_id;
            $token->save();
            return CommonHelper::responseSuccess(__('token_updated_successfully'));
        }
        if ($token && $request->has('language_id')) {
            $token->language_id = $language_id;
            $token->save();
        }

        AdminToken::updateOrCreate(
            ['user_id' => $user_id, 'fcm_token' => $request->fcm_token],
            ['type' => $type, 'platform' => $request->platform ?? 'android', 'language_id' => $language_id]
        );
        return CommonHelper::responseSuccess(__('token_added_successfully'));
    }

    /**
     * Send OTP to delivery boy for login
     * POST /api/delivery_boy/send-otp
     */
    public function deliveryBoySendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string',
            'name' => 'required|string',
        ]);

        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        try {
            $searchPhone = trim($request->phone_number);
            $searchName = strtolower(trim($request->name));
            
            // Log for debugging
            Log::info('OTP Send Request', [
                'phone' => $searchPhone,
                'name' => $searchName,
            ]);
            
            // Find delivery boy by phone and name (case-insensitive, trim whitespace)
            $deliveryBoy = DeliveryBoy::where('mobile', $searchPhone)
                ->whereRaw('LOWER(TRIM(name)) = ?', [$searchName])
                ->with('admin')
                ->first();

            if (!$deliveryBoy) {
                // Debug: show what's in database for this phone
                $allWithPhone = DeliveryBoy::where('mobile', $searchPhone)
                    ->select('id', 'name', 'mobile', 'status')
                    ->get()
                    ->toArray();
                
                Log::error('No delivery boy found', [
                    'searched_phone' => $searchPhone,
                    'searched_name' => $searchName,
                    'found_with_phone' => $allWithPhone,
                ]);
                
                return CommonHelper::responseError('no_delivery_boy_found_with_this_phone_number_and_name');
            }

            // Check delivery boy status
            if ($deliveryBoy->status == DeliveryBoy::$statusRegistered) {
                return CommonHelper::responseError('your_request_under_review_you_will_get_notification_after_get_approval');
            }

            if ($deliveryBoy->status == DeliveryBoy::$statusRejected) {
                $data["status"] = $deliveryBoy->status;
                $data["remark"] = $deliveryBoy->remark ?? "";
                return CommonHelper::responseErrorWithData('your_request_rejected_please_contact_to_administrator_for_approval', $data);
            }

            if ($deliveryBoy->status == DeliveryBoy::$statusBlocked) {
                return CommonHelper::responseError('your_account_is_blocked_please_contact_to_administrator_for_activate');
            }

            // Generate 4-digit OTP
            $otp = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
            
            // Save OTP with 10 minutes expiry
            $deliveryBoy->otp = $otp;
            $deliveryBoy->otp_expires_at = Carbon::now()->addMinutes(10);
            $deliveryBoy->save();

            // Get API key from settings
            $apiKey = \App\Models\Setting::get_value('twofactor_api_key');
            
            if (empty($apiKey)) {
                return CommonHelper::responseError('sms_service_not_configured_please_contact_administrator');
            }

            // Send OTP via 2Factor.in API using the saved OTP
            $phoneNumber = $request->phone_number;
            $url = "https://2factor.in/API/V1/{$apiKey}/SMS/{$phoneNumber}/{$otp}/Login+OTP";

            try {
                $response = @file_get_contents($url);
                if ($response === false) {
                    Log::error('2Factor OTP Send Error: Failed to connect to API');
                    return CommonHelper::responseError('failed_to_send_otp_please_try_again');
                }
                
                $result = json_decode($response, true);

                if (isset($result['Status']) && $result['Status'] == 'Success') {
                    return CommonHelper::responseSuccess('otp_sent_successfully_please_check_your_phone');
                } else {
                    Log::error('2Factor OTP Send Error', ['response' => $result]);
                    return CommonHelper::responseError('failed_to_send_otp_please_try_again');
                }
            } catch (\Exception $e) {
                Log::error('2Factor API Exception: ' . $e->getMessage());
                return CommonHelper::responseError('failed_to_send_otp_please_try_again');
            }

        } catch (\Exception $e) {
            Log::error('Send OTP Error: ' . $e->getMessage());
            return CommonHelper::responseError('something_went_wrong');
        }
    }

    /**
     * Verify OTP and login delivery boy
     * POST /api/delivery_boy/verify-otp
     */
    public function deliveryBoyVerifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string',
            'otp' => 'required|string|size:4',
        ]);

        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        try {
            // Find delivery boy by phone number
            $deliveryBoy = DeliveryBoy::where('mobile', $request->phone_number)
                ->with('admin')
                ->first();

            if (!$deliveryBoy) {
                return CommonHelper::responseError('no_delivery_boy_found_with_this_phone_number');
            }

            // Check if OTP exists and not expired
            if (empty($deliveryBoy->otp)) {
                return CommonHelper::responseError('please_request_otp_first');
            }

            if (Carbon::now()->greaterThan($deliveryBoy->otp_expires_at)) {
                return CommonHelper::responseError('otp_has_expired_please_request_new_otp');
            }

            // Verify OTP
            if ($deliveryBoy->otp !== $request->otp) {
                return CommonHelper::responseError('invalid_otp_please_try_again');
            }

            // Clear OTP after successful verification
            $deliveryBoy->otp = null;
            $deliveryBoy->otp_expires_at = null;
            $deliveryBoy->save();

            $user = $deliveryBoy->admin;

            if (!$user) {
                return CommonHelper::responseError('user_account_not_found');
            }

            // Login the user
            Auth::login($user, false);

            // Handle FCM token if provided
            if (isset($request->fcm_token) && !empty($request->fcm_token)) {
                $language_id = $request->input('language_id');
                if (empty($language_id) && $request->filled('language_code')) {
                    $lang = app(LanguageService::class)->getLanguageByCode($request->language_code);
                    $language_id = $lang ? $lang->id : null;
                }
                $language_id = $language_id ?: CommonHelper::getDefaultLanguageId();

                AdminToken::updateOrCreate(
                    ['user_id' => $user->id, 'fcm_token' => $request->fcm_token],
                    [
                        'type' => Role::$roleNameDeliveryBoy,
                        'platform' => $request->platform ?? 'android',
                        'language_id' => $language_id
                    ]
                );
            }

            // Generate access token
            $accessToken = $user->createToken('authToken')->accessToken;

            // Prepare response data
            $data = $deliveryBoy->makeHidden(['admin', 'translations'])->toArray();
            $data['email'] = $user->email;
            $data['username'] = $user->username;
            $data['allPermissions'] = $user->allPermissions;
            $data['access_token'] = $accessToken;

            return CommonHelper::responseWithData($data, false);

        } catch (\Exception $e) {
            Log::error('Verify OTP Error: ' . $e->getMessage());
            return CommonHelper::responseError('something_went_wrong');
        }
    }

    private function getAdminRoleType($user)
    {
        if (!$user || !isset($user->role_id)) {
            return Role::$roleNameAdmin;
        }
        if ($user->role_id == Role::$roleDeliveryBoy) {
            return Role::$roleNameDeliveryBoy;
        }
        if ($user->role_id == Role::$roleSuperAdmin) {
            return Role::$roleNameSuperAdmin;
        }
        if ($user->isStoreUser()) {
            return Role::$roleNameStore;
        }
        return Role::$roleNameAdmin;
    }
}
