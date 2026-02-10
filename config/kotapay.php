<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Kotapay API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the Kotapay ACH payment API.
    |
    */

    'enabled' => env('KOTAPAY_API_ENABLED', false),

    'base_url' => env('KOTAPAY_API_BASE_URL', 'https://api.kotapay.com'),

    'client_id' => env('KOTAPAY_API_CLIENT_ID', ''),

    'client_secret' => env('KOTAPAY_API_CLIENT_SECRET', ''),

    'username' => env('KOTAPAY_API_USERNAME', ''),

    'password' => env('KOTAPAY_API_PASSWORD', ''),

    'company_id' => env('KOTAPAY_API_COMPANY_ID', ''),

    /*
    |--------------------------------------------------------------------------
    | Token Cache
    |--------------------------------------------------------------------------
    |
    | The access token expires after 300 seconds (5 minutes).
    | We cache it with a buffer to prevent using expired tokens.
    |
    */

    'token_cache_key' => 'kotapay_access_token',
    'token_cache_ttl' => 270, // 4.5 minutes (300 - 30 second buffer)

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | The timeout in seconds for API requests.
    |
    */

    'timeout' => env('KOTAPAY_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | API Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Configure rate limiting for API calls to prevent quota exhaustion.
    |
    */

    'rate_limit' => [
        'enabled' => env('KOTAPAY_RATE_LIMIT_ENABLED', true),
        'max_requests_per_hour' => env('KOTAPAY_RATE_LIMIT_PER_HOUR', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Settings
    |--------------------------------------------------------------------------
    |
    | Configure retry behavior for transient API failures.
    |
    */

    'retry' => [
        'enabled' => env('KOTAPAY_RETRY_ENABLED', true),
        'max_attempts' => env('KOTAPAY_RETRY_MAX_ATTEMPTS', 3),
        'delay_ms' => env('KOTAPAY_RETRY_DELAY_MS', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Customer Model
    |--------------------------------------------------------------------------
    |
    | The model that uses the AchBillable trait.
    |
    */

    'model' => env('KOTAPAY_CUSTOMER_MODEL', 'App\\Models\\Customer'),
];
