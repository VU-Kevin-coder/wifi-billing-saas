<?php

$sourceCidrs = trim((string) env('RADIUS_POLICY_ALLOWED_SOURCE_CIDRS', ''));

return [
    // Must be independently generated; never reuse APP_KEY, router, site-agent,
    // payment, or customer credentials.
    'service_secret' => env('RADIUS_LARAVEL_SERVICE_SECRET'),
    // Comma-separated direct TCP peer CIDRs for the private adapter channel.
    // Production requests fail closed when this list is missing or invalid.
    'allowed_source_cidrs' => $sourceCidrs === '' ? [] : array_map('trim', explode(',', $sourceCidrs)),
    'nonce_cache_store' => env('RADIUS_POLICY_NONCE_CACHE_STORE'),
    'clock_skew_seconds' => (int) env('RADIUS_POLICY_CLOCK_SKEW_SECONDS', 60),
    'lease_seconds' => (int) env('RADIUS_POLICY_LEASE_SECONDS', 60),
    'retry_seconds' => (int) env('RADIUS_POLICY_RETRY_SECONDS', 30),
];
