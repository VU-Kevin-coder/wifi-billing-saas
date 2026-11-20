<?php

return [
    'enabled' => (bool) env('RADIUS_ENABLED', false),
    'production_enabled' => (bool) env('RADIUS_PRODUCTION_ENABLED', false),
    'lab_accounting_log' => env('RADIUS_LAB_ACCOUNTING_LOG', true),
    'bridge_health_url' => env('RADIUS_POLICY_BRIDGE_HEALTH_URL', 'http://radius-policy-bridge:8080/health'),
    'health_secret' => env('RADIUS_HEALTH_SECRET', ''),
    'health_host' => env('RADIUS_HEALTH_HOST', 'app'),
    'health_port' => (int) env('RADIUS_HEALTH_PORT', 18121),
];
