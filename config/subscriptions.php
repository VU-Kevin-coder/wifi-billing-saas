<?php

return [
    // Do not enable until an independently reviewed subscription gateway, operations,
    // reconciliation, financial/security controls and the manual-admin gap are addressed.
    'checkout_enabled' => env('SUBSCRIPTION_CHECKOUT_ENABLED', false),
];
