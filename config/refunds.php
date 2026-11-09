<?php

return [

    // Sales older than this cannot be refunded through the system (0 = no limit).
    'window_days' => (int) env('REFUND_WINDOW_DAYS', 90),
];
