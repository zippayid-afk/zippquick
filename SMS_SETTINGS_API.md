# SMS Settings API Endpoint

## Summary
Added new endpoint `/settings/sms` to the customer API that returns SMS gateway configuration needed for the Flutter user app to send OTPs.

## What Was Fixed

### The Bug
The Flutter user app was trying to fetch SMS settings from `/settings/sms` endpoint, but this endpoint **did not exist** in the admin backend. This caused the 2Factor API key initialization to fail silently, resulting in the error:
```
2Factor API key not initialized. Call OtpService.initialize() first
```

### Root Cause
1. The customer API routes at `routes/customer.php` only had 3 settings endpoints:
   - `GET /settings/` (general settings)
   - `GET /settings/payment_methods` (payment config)
   - `GET /settings/get_seo_settings` (SEO config)

2. There was **NO** `/settings/sms` endpoint to return SMS gateway configuration

3. The OtpService in the Flutter app tried to call this missing endpoint, the request failed, and the fallback API key was empty, leaving the service uninitialized

## The Fix

### 1. Added `getSmsSettings()` Method
**File:** `/Volumes/Projects/Snap/Snap/admin/app/Http/Controllers/API/Customer/SettingApiController.php`

New method that:
- Queries the `settings` table for SMS-related variables:
  - `twofactor_api_key`
  - `msg91_auth_key`
  - `twilio_sid`
  - `twilio_auth_token`
  - `fast2sms_api_key`
  - `sms_gateway`
- Returns only non-empty values for security
- Wraps response in `CommonHelper::responseSuccessWithData()`

### 2. Added Route
**File:** `/Volumes/Projects/Snap/Snap/admin/routes/customer.php`

```php
Route::get('sms', [SettingApiController::class, 'getSmsSettings']);
```

This route is inside the settings group, so the full URL is:
- `GET /customer/settings/sms`

## API Response Format

**Endpoint:** `GET http://127.0.0.1:8000/customer/settings/sms`

**Success Response (200):**
```json
{
  "status": 1,
  "message": "SMS settings fetched",
  "data": {
    "twofactor_api_key": "your-api-key-here",
    "sms_gateway": "2factor"
  }
}
```

**Empty Response (when no SMS settings configured):**
```json
{
  "status": 1,
  "message": "SMS settings fetched",
  "data": {}
}
```

## How the Flutter App Uses It

**File:** `/Volumes/Projects/Snap/Snap/user/lib/core/services/otp_service.dart`

During app initialization:
1. Calls `OtpService.initialize()`
2. Which calls `ApiClient().get('settings/sms')`
3. Extracts `twofactor_api_key` from response data
4. Stores it in `_twoFactorApiKey` for use when sending OTPs

When user taps "Get OTP":
1. Calls `OtpService().sendOtp(phoneNumber)`
2. Uses the stored `_twoFactorApiKey` to call 2Factor.in API
3. OTP is delivered to the user's phone

## Testing the Fix

1. Make sure SMS gateway settings are configured in the admin panel at `/settings/sms`
2. Verify the 2Factor API key is set and saved in the database
3. Run the Flutter user app with `flutter clean && flutter run`
4. Go to login screen and try sending an OTP
5. Should now work without the "2Factor API key not initialized" error

## Files Modified

1. ✅ `/Volumes/Projects/Snap/Snap/admin/routes/customer.php` - Added SMS route
2. ✅ `/Volumes/Projects/Snap/Snap/admin/app/Http/Controllers/API/Customer/SettingApiController.php` - Added getSmsSettings() method
3. ✅ `/Volumes/Projects/Snap/Snap/user/lib/core/services/otp_service.dart` - Updated to fetch from `/settings/sms`
