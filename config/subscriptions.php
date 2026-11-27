<?php

return [
    // Explicit kill switch: voucher gateway credentials alone never enable charges.
    'checkout_enabled' => env('SUBSCRIPTION_CHECKOUT_ENABLED', false),
];
