<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Google Meet Service Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Google Meet integration
    | Currently using dynamic link generation (no API required)
    | For advanced features, configure Google Calendar API credentials
    |
    */

    'google_meet' => [
        'client_id' => env('GOOGLE_MEET_CLIENT_ID', ''),
        'client_secret' => env('GOOGLE_MEET_CLIENT_SECRET', ''),
        'redirect_uri' => env('GOOGLE_MEET_REDIRECT_URI', 'http://localhost:8000/oauth/callback'),
        'access_token' => env('GOOGLE_MEET_ACCESS_TOKEN', ''),

        // Meeting configuration
        'meeting' => [
            'duration_minutes' => env('GOOGLE_MEET_DURATION', 30),
            'enable_recording' => env('GOOGLE_MEET_ENABLE_RECORDING', false),
            'require_participant_approval' => env('GOOGLE_MEET_REQUIRE_PARTICIPANT_APPROVAL', false),
        ],
    ],
];
