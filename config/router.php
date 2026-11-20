<?php

return [
    'lab_provisioning_enabled' => (bool) env('ROUTER_LAB_PROVISIONING_ENABLED', false),
    'api_ca_cert_path' => env('MIKROTIK_CA_CERT_PATH'),
    'testing_allow_plaintext_api' => filter_var(
        env('ROUTER_TESTING_ALLOW_PLAINTEXT_API', false),
        FILTER_VALIDATE_BOOL,
    ),
];
