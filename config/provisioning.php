<?php

return [

    // Alert the operator when a paid session is still not active after this long.
    'alert_after_minutes' => (int) env('PROVISIONING_ALERT_AFTER_MINUTES', 10),

    // If activation is delayed beyond the grace period, the customer's full
    // package duration starts when access really begins (not when they paid).
    'compensate_delay' => filter_var(env('PROVISIONING_COMPENSATE_DELAY', true), FILTER_VALIDATE_BOOL),
    'grace_minutes' => (int) env('PROVISIONING_GRACE_MINUTES', 2),
];
