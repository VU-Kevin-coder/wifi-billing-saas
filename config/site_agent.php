<?php

return [
    'required' => filter_var(
        env('SITE_AGENT_REQUIRED', env('APP_ENV', 'production') === 'production'),
        FILTER_VALIDATE_BOOL,
    ),

    // This map is read only by a site-local worker. Never set it on the SaaS host.
    'routers' => json_decode((string) env('SITE_AGENT_ROUTERS', '{}'), true) ?: [],
];
