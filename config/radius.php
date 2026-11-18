<?php

return [
    'enabled' => (bool) env('RADIUS_ENABLED', false),
    'production_enabled' => (bool) env('RADIUS_PRODUCTION_ENABLED', false),
    'lab_accounting_log' => env('RADIUS_LAB_ACCOUNTING_LOG', true),
];
