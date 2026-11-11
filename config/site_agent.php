<?php

return [
    'required' => filter_var(
        env('SITE_AGENT_REQUIRED', env('APP_ENV', 'production') === 'production'),
        FILTER_VALIDATE_BOOL,
    ),

    'allow_direct_router_connection' => filter_var(env('ALLOW_DIRECT_ROUTER_CONNECTION', false), FILTER_VALIDATE_BOOL),
    'router_ssl_verify_peer' => filter_var(
        env('MIKROTIK_SSL_VERIFY_PEER', env('APP_ENV', 'production') === 'production'),
        FILTER_VALIDATE_BOOL,
    ),
    'router_ca_cert_path' => env('MIKROTIK_CA_CERT_PATH'),

    // This map is read only by a site-local worker. Never set it on the SaaS host.
    'routers' => json_decode((string) env('SITE_AGENT_ROUTERS', '{}'), true) ?: [],
];
