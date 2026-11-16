<?php

return [
    // Largest single manual wallet adjustment (in major currency units).
    'max_wallet_adjustment' => (int) env('MAX_WALLET_ADJUSTMENT', 1000000),

    // A withdrawal whose provider transfer cannot be found after this many
    // hours is flagged for manual review (it is never auto-refunded).
    'withdrawal_manual_review_hours' => (int) env('WITHDRAWAL_MANUAL_REVIEW_HOURS', 24),

    'simulation_enabled' => filter_var(env('PAYMENT_SIMULATION_ENABLED', false), FILTER_VALIDATE_BOOL),

    // Staging is guarded automatically; other lab environments must opt in.
    'lab_mode' => filter_var(env('PAYMENT_LAB_MODE', false), FILTER_VALIDATE_BOOL),

    // Backwards-compatible switch; also enables all lab restrictions.
    'sandbox_only' => filter_var(env('PAYMENTS_SANDBOX_ONLY', false), FILTER_VALIDATE_BOOL),
];
