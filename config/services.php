<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'google' => [
        'geocoding_key' => env('GOOGLE_GEOCODING_KEY'),
    ],

    'weatherapi' => [
        'key' => env('WEATHERAPI_KEY'),
    ],

    'weatherkit' => [
        'team_id' => env('APPLE_WEATHERKIT_TEAM_ID'),
        'service_id' => env('APPLE_WEATHERKIT_SERVICE_ID'),
        'key_id' => env('APPLE_WEATHERKIT_KEY_ID'),
        // Path (relative to project root) to the .p8 private key; the file is
        // git-ignored and read via base_path() at bind time.
        'private_key_path' => env('APPLE_WEATHERKIT_PRIVATE_KEY'),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google_analytics' => [
        // GA4 Measurement ID (e.g. G-XXXXXXXXXX). When empty the gtag.js tag is
        // not rendered, so local/dev traffic never reaches Analytics — set it
        // only in the environments you want to measure (prod, staging).
        'measurement_id' => env('GOOGLE_ANALYTICS_ID'),
    ],

];
