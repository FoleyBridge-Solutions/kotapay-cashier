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

    /*
    |--------------------------------------------------------------------------
    | Automated Report API (InitAutomatedSession.icp / GetAutomatedReport.icp)
    |--------------------------------------------------------------------------
    |
    | Kotapay's "automated report download" system. This is SEPARATE from the
    | OAuth api.kotapay.com REST API above:
    |
    |   - Host: https://www.kotapay.com  (the secure portal host)
    |   - Auth: two-hit grid-card multifactor flow (techdoc v7.6):
    |       1) First hit  POST login (+ optional soft_vendor/name/version) to
    |          InitAutomatedSession.icp -> XML (xdoc) with errorcode,
    |          challenge1/2/3, secid, serial, sessionid, instancenumber (ICIN),
    |          cookiename. The cookiename + sessionid are returned explicitly;
    |          do NOT construct them.
    |       2) Second hit POST pass + response1/2/3 (MFA card lookups) + secid +
    |          report params, with the session cookie (cookiename=sessionid),
    |          to GetAutomatedReport.icp?&ICIN=<ICIN> -> report file (or an
    |          errors.txt / xdoc on failure).
    |
    | SECURITY: 'mfa_card' is an authentication secret (like an MFA seed).
    | NEVER commit it. Provide it via the KOTAPAY_AUTOREPORT_MFA_CARD env var
    | as a JSON object: {"1":{"A":"V","B":"H",...},"2":{...},...}. The
    | account locks for 30 minutes after 3 failed authentication attempts,
    | so the client MUST reuse sessions and never brute-force.
    |
    */

    'auto_report' => [
        'enabled' => env('KOTAPAY_AUTOREPORT_ENABLED', false),
        'base_url' => env('KOTAPAY_AUTOREPORT_BASE_URL', 'https://www.kotapay.com'),
        'init_endpoint' => env('KOTAPAY_AUTOREPORT_INIT_ENDPOINT', '/InitAutomatedSession.icp'),
        'report_endpoint' => env('KOTAPAY_AUTOREPORT_REPORT_ENDPOINT', '/GetAutomatedReport.icp'),
        'login' => env('KOTAPAY_AUTOREPORT_LOGIN', ''),
        'password' => env('KOTAPAY_AUTOREPORT_PASSWORD', ''),
        'serial' => env('KOTAPAY_AUTOREPORT_SERIAL', ''),
        // Optional software-identification fields sent on the first hit only;
        // stored in Kotapay's session log and helpful to their Support.
        'soft_vendor' => env('KOTAPAY_AUTOREPORT_SOFT_VENDOR', 'FoleyBridgeSolutions'),
        'soft_name' => env('KOTAPAY_AUTOREPORT_SOFT_NAME', 'Tax-Axis'),
        'soft_version' => env('KOTAPAY_AUTOREPORT_SOFT_VERSION', '1.0'),
        // JSON-encoded grid card: {"1":{"A":"V",...,"J":"C"}, ... ,"5":{...}}
        'mfa_card' => env('KOTAPAY_AUTOREPORT_MFA_CARD', ''),
        'timeout' => env('KOTAPAY_AUTOREPORT_TIMEOUT', 60),
        // Cache key for a live session (cookiename + sessionid + ICIN) so we
        // reuse it instead of re-authenticating (avoids lockout).
        'session_cache_key' => 'kotapay_autoreport_session',
        'session_cache_ttl' => env('KOTAPAY_AUTOREPORT_SESSION_TTL', 600),
    ],
];
