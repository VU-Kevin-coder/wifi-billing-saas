<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | "sandbox" or "production". Drives the default base URL. You may still
    | override the base URL explicitly below (e.g. for a self-hosted proxy).
    |
    */
    'environment' => env('MOMO_ENVIRONMENT', 'sandbox'),

    'base_url' => env(
        'MOMO_BASE_URL',
        env('MOMO_ENVIRONMENT', 'sandbox') === 'production'
            ? 'https://momodeveloper.mtn.com'
            : 'https://sandbox.momodeveloper.mtn.com'
    ),

    /*
    |--------------------------------------------------------------------------
    | HTTP client
    |--------------------------------------------------------------------------
    */
    'http' => [
        'timeout' => (int) env('MOMO_HTTP_TIMEOUT', 30),
        'retry_times' => (int) env('MOMO_HTTP_RETRY_TIMES', 1),
        'retry_sleep_ms' => (int) env('MOMO_HTTP_RETRY_SLEEP_MS', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Token cache
    |--------------------------------------------------------------------------
    |
    | OAuth2 access tokens are cached to avoid hitting the /token endpoint on
    | every call. Set the cache store to use (null = default app store) and a
    | safety buffer (seconds) subtracted from the token's expires_in.
    |
    */
    'cache' => [
        'store' => env('MOMO_CACHE_STORE'),
        'prefix' => env('MOMO_CACHE_PREFIX', 'mtn_momo_api'),
        'expiry_buffer' => (int) env('MOMO_CACHE_EXPIRY_BUFFER', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Products
    |--------------------------------------------------------------------------
    |
    | Each MTN MoMo product (Collection, Disbursement, Remittance) is
    | provisioned independently on the MoMo Developer portal and has its own
    | subscription key, API user and API key. target_environment is the
    | value MTN gave you when you provisioned the API user (usually
    | "sandbox" in sandbox, or a provider-specific name in production).
    |
    */
    'collection' => [
        'subscription_key' => env('MOMO_COLLECTION_SUBSCRIPTION_KEY'),
        'api_user' => env('MOMO_COLLECTION_API_USER'),
        'api_key' => env('MOMO_COLLECTION_API_KEY'),
        'target_environment' => env('MOMO_COLLECTION_TARGET_ENVIRONMENT', 'sandbox'),
        'callback_host' => env('MOMO_COLLECTION_CALLBACK_HOST'),
        'currency' => env('MOMO_COLLECTION_CURRENCY', 'EUR'),
    ],

    'disbursement' => [
        'subscription_key' => env('MOMO_DISBURSEMENT_SUBSCRIPTION_KEY'),
        'api_user' => env('MOMO_DISBURSEMENT_API_USER'),
        'api_key' => env('MOMO_DISBURSEMENT_API_KEY'),
        'target_environment' => env('MOMO_DISBURSEMENT_TARGET_ENVIRONMENT', 'sandbox'),
        'callback_host' => env('MOMO_DISBURSEMENT_CALLBACK_HOST'),
        'currency' => env('MOMO_DISBURSEMENT_CURRENCY', 'EUR'),
    ],

    'remittance' => [
        'subscription_key' => env('MOMO_REMITTANCE_SUBSCRIPTION_KEY'),
        'api_user' => env('MOMO_REMITTANCE_API_USER'),
        'api_key' => env('MOMO_REMITTANCE_API_KEY'),
        'target_environment' => env('MOMO_REMITTANCE_TARGET_ENVIRONMENT', 'sandbox'),
        'callback_host' => env('MOMO_REMITTANCE_CALLBACK_HOST'),
        'currency' => env('MOMO_REMITTANCE_CURRENCY', 'EUR'),
    ],

];
