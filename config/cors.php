<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'delivery_boy/*', 'customer/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:3000',      // Next.js local dev
        'http://127.0.0.1:3000',
        'capacitor://localhost',
    ],

    'allowed_origins_patterns' => [
        '#http://localhost:\d+#',                    // localhost on any port
        '#http://127\.0\.0\.1:\d+#',                // 127.0.0.1 on any port
        '#http://192\.168\..*#',                    // Local WiFi development
        '#http://[0-9a-f:]+:\d+#',                  // IPv6 localhost
        '#http://.*\.local:\d+#',                   // .local domains on any port
    ],

    'allowed_headers' => [
        'Accept',
        'Accept-Language',
        'Content-Language',
        'Content-Type',
        'Authorization',
        'X-Requested-With',
        'X-CSRF-Token',
        'Origin',
        'Cache-Control',
        'X-Access-Key',
        'Channel',
    ],

    'exposed_headers' => [
        'authorization',
        'content-length',
        'content-type',
        'x-auth-token',
        'x-csrf-token',
    ],

    'max_age' => 86400,  // 24 hours

    'supports_credentials' => true,

];
