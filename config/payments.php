<?php

return [
    // Largest single manual wallet adjustment (in major currency units).
    'max_wallet_adjustment' => (int) env('MAX_WALLET_ADJUSTMENT', 1000000),

    // A withdrawal whose provider transfer cannot be found after this many
    // hours is flagged for manual review (it is never auto-refunded).
    'withdrawal_manual_review_hours' => (int) env('WITHDRAWAL_MANUAL_REVIEW_HOURS', 24),

    'simulation_enabled' => filter_var(env('PAYMENT_SIMULATION_ENABLED', false), FILTER_VALIDATE_BOOL),
];
