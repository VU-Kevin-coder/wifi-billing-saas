<?php

return [
    // Must be independently generated; never reuse APP_KEY, router, site-agent,
    // payment, or customer credentials.
    'service_secret' => env('RADIUS_LARAVEL_SERVICE_SECRET'),
    'nonce_cache_store' => env('RADIUS_POLICY_NONCE_CACHE_STORE'),
    'clock_skew_seconds' => (int) env('RADIUS_POLICY_CLOCK_SKEW_SECONDS', 60),
    'lease_seconds' => (int) env('RADIUS_POLICY_LEASE_SECONDS', 60),
    'retry_seconds' => (int) env('RADIUS_POLICY_RETRY_SECONDS', 30),
];
