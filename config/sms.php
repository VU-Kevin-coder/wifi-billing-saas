<?php

return [

    // The platform pays for SMS, so each business has a monthly ceiling on
    // messages sent on its behalf (0 = no ceiling). Counted per reporting month.
    'monthly_cap_per_tenant' => (int) env('SMS_MONTHLY_CAP', 300),

    // Give up on a session's reminder after this many failed attempts.
    'max_attempts' => (int) env('SMS_MAX_ATTEMPTS', 3),
];
