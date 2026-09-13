# Dboy Integration Setup Guide

Complete setup instructions for connecting dboy (Flutter delivery app) with admin backend.

## Prerequisites

- PHP 8.1+
- Laravel 11+
- Composer
- Node.js 16+ (for WebSocket)
- Flutter SDK (for dboy app)
- Database (MySQL/SQLite)

---

## Step 1: Admin Backend Setup

### 1.1 Install Dependencies
```bash
cd /Volumes/Projects/Snap/Snap/admin
composer install
```

### 1.2 Configure Environment
```bash
cp .env.example .env
php artisan key:generate
```

**Key settings in `.env`:**
```
APP_URL=http://127.0.0.1:8000
DB_CONNECTION=mysql  # or sqlite
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=snap
DB_USERNAME=root
DB_PASSWORD=
```

### 1.3 Database Setup
```bash
php artisan migrate
php artisan db:seed  # If seeds exist
```

### 1.4 Setup Laravel Passport (OAuth2)

**Check if Passport is already installed:**
```bash
php artisan passport:install --force
```

This creates:
- OAuth2 Personal Access Client
- OAuth2 Password Grant Client
- Encryption keys for tokens

**Output will show:**
```
Personal access client created successfully.
Client ID: 1
Client secret: xxxxxxxxxxxxxxxxxxxxx

Password grant client created successfully.
Client ID: 2
Client secret: xxxxxxxxxxxxxxxxxxxxx
```

**Save these credentials** - they're needed for token generation if dboy uses password grant flow.

### 1.5 Create Test Delivery Boy Account (Optional)
```bash
php artisan tinker

# In tinker shell:
$admin = App\Models\Admin::firstOrCreate(
    ['email' => 'delivery@example.com'],
    [
        'name' => 'Test Delivery Boy',
        'password' => bcrypt('password123'),
        'type' => 'delivery_boy',
        'country_id' => 1,
    ]
);

$admin->createToken('dboy-token')->plainTextToken;
```

### 1.6 Clear Cache
```bash
php artisan config:clear
php artisan cache:clear
```

---

## Step 2: Start Admin Services

### 2.1 Start Laravel Development Server
```bash
# Terminal 1: Laravel server
cd /Volumes/Projects/Snap/Snap/admin
php artisan serve
```

Runs on: `http://127.0.0.1:8000`

### 2.2 Start WebSocket Server (Reverb)
```bash
# Terminal 2: WebSocket server
cd /Volumes/Projects/Snap/Snap/admin
php artisan reverb:start
```

Runs on: `ws://127.0.0.1:9090`

### 2.3 Start Queue Worker (Optional, for background jobs)
```bash
# Terminal 3: Queue worker
cd /Volumes/Projects/Snap/Snap/admin
php artisan queue:work database
```

---

## Step 3: Dboy Flutter App Setup

### 3.1 Update Configuration

**File**: `/Volumes/Projects/Snap/Snap/dboy/env/dev.json`

```json
{
  "BASE_URL": "http://127.0.0.1:8000/delivery_boy/",
  "SOCKET_BASE_URL": "ws://127.0.0.1:9090/ws/chat"
}
```

**File**: `/Volumes/Projects/Snap/Snap/dboy/lib/core/configs/app_config.dart`

```dart
static const String environment = 'dev';  // Use 'dev' instead of 'demo'
```

### 3.2 Install Dependencies & Build
```bash
cd /Volumes/Projects/Snap/Snap/dboy

# Install Flutter packages
flutter pub get

# Apply configuration from env/dev.json
dart run tool/apply_config.dart

# Clean build
flutter clean

# Run on emulator/device
flutter run

# Or run on specific device
flutter run -d emulator-5554
```

### 3.3 Firebase Configuration (Optional)
For push notifications, configure Firebase:

1. Create Firebase project
2. Add Android/iOS app in Firebase Console
3. Download `google-services.json` (Android) or `GoogleService-Info.plist` (iOS)
4. Place in appropriate directories:
   - Android: `android/app/google-services.json`
   - iOS: `ios/Runner/GoogleService-Info.plist`

---

## Step 4: Test Connectivity

### 4.1 Test Admin API Health
```bash
curl -X GET http://127.0.0.1:8000/delivery_boy/settings

# Expected: 200 OK with settings JSON
```

### 4.2 Test Login (cURL)
```bash
curl -X POST http://127.0.0.1:8000/delivery_boy/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "delivery@example.com",
    "password": "password123",
    "fcm_token": "test_token",
    "platform": "android",
    "language_id": 1
  }'

# Expected: 200 OK with access_token
```

### 4.3 Test Protected Endpoint
```bash
# Use the token from login response
TOKEN="your_access_token_here"

curl -X GET http://127.0.0.1:8000/delivery_boy/dashboard \
  -H "Authorization: Bearer $TOKEN"

# Expected: 200 OK with dashboard data
```

### 4.4 Test in Dboy App
1. Launch the app on emulator/device
2. Go to Login screen
3. Enter test credentials:
   - Email: `delivery@example.com`
   - Password: `password123`
4. Should login successfully and show Dashboard

---

## Step 5: Configuration Summary

### Admin Backend
| Item | Value |
|------|-------|
| Base URL | `http://127.0.0.1:8000` |
| API Endpoint | `http://127.0.0.1:8000/delivery_boy/` |
| WebSocket | `ws://127.0.0.1:9090/ws/chat` |
| Auth Guard | `auth:api` (Passport) |
| CORS Enabled | Yes (for localhost & local IP) |

### Dboy App
| Item | Value |
|------|-------|
| Environment | `dev` |
| API Base URL | `http://127.0.0.1:8000/delivery_boy/` |
| WebSocket URL | `ws://127.0.0.1:9090/ws/chat` |
| Token Storage | Flutter Secure Storage |

---

## Step 6: Common Issues & Solutions

### Issue: "Connection refused" on API call
**Cause**: Admin backend not running
```bash
# Check if running
curl http://127.0.0.1:8000/delivery_boy/settings

# Start server
php artisan serve
```

### Issue: "401 Unauthorized" on protected endpoints
**Cause**: Invalid/expired token
```bash
# Test with new login
curl -X POST http://127.0.0.1:8000/delivery_boy/login \
  -H "Content-Type: application/json" \
  -d '{"email":"delivery@example.com","password":"password123","fcm_token":"test","platform":"android","language_id":1}'
```

### Issue: "CORS error" in browser console
**Cause**: CORS not configured
**Solution**: Verify `config/cors.php` includes:
- `delivery_boy/*` in paths
- `http://127.0.0.1:*` in allowed_origins
- `supports_credentials` => true

### Issue: WebSocket connection fails
**Cause**: Reverb server not running
```bash
# In another terminal
php artisan reverb:start

# Should output: Starting WebSocket server on 127.0.0.1:9090
```

### Issue: Flutter app can't connect to local Laravel
**Cause**: App using wrong IP address
**Solution**: 
- Use `127.0.0.1` for Android emulator
- Use local machine IP for physical device (e.g., `192.168.x.x`)
- Update `env/dev.json` accordingly

### Issue: Token expiration on protected endpoints
**Cause**: Access token expired
**Solution**: Implement token refresh in app or re-login

---

## Step 7: Production Deployment

When deploying to production:

1. **Update URLs in dboy**:
   ```json
   // env/prod.json
   {
     "BASE_URL": "https://api.snapbuy.com/delivery_boy/",
     "SOCKET_BASE_URL": "wss://api.snapbuy.com/ws/chat"
   }
   ```

2. **Update admin environment**:
   ```
   APP_URL=https://api.snapbuy.com
   APP_ENV=production
   APP_DEBUG=false
   ```

3. **Enable HTTPS everywhere**

4. **Update CORS in production**:
   ```php
   'allowed_origins' => [
       'https://app.snapbuy.com',
       'https://admin.snapbuy.com',
   ],
   ```

5. **Setup SSL certificates**

6. **Configure WebSocket for production** (using proper reverse proxy)

---

## Verification Checklist

- [ ] Laravel backend running on `http://127.0.0.1:8000`
- [ ] WebSocket server running on `ws://127.0.0.1:9090`
- [ ] Database migrated and seeded
- [ ] Passport installed with OAuth clients
- [ ] CORS configured for `delivery_boy/*`
- [ ] Test delivery boy account created
- [ ] Flutter app configured with `dev` environment
- [ ] `env/dev.json` has correct URLs
- [ ] Can login via API (cURL test)
- [ ] Can access protected endpoints with token
- [ ] Flutter app can connect and login successfully
- [ ] WebSocket chat working

---

## Quick Start Summary

```bash
# Terminal 1: Admin Server
cd /Volumes/Projects/Snap/Snap/admin
php artisan serve

# Terminal 2: WebSocket Server
cd /Volumes/Projects/Snap/Snap/admin
php artisan reverb:start

# Terminal 3: Dboy Flutter App
cd /Volumes/Projects/Snap/Snap/dboy
dart run tool/apply_config.dart
flutter run
```

All three should be running simultaneously for full functionality.

---

**Created**: 2024-01-15
**Version**: 1.0
**Status**: Ready for Development
