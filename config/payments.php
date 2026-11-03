<?php

return [
    'simulation_enabled' => filter_var(env('PAYMENT_SIMULATION_ENABLED', false), FILTER_VALIDATE_BOOL),
];
