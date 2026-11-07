<?php

return [

    // Platform administrators must have two-factor authentication enabled
    // before they can use the application (enrolment page stays reachable).
    'platform_admin_mfa_required' => filter_var(env('PLATFORM_ADMIN_MFA_REQUIRED', true), FILTER_VALIDATE_BOOL),

    // Money-moving and credential-changing actions require a recent
    // two-factor verification ("step-up"). See the mfa.recent middleware.
    'step_up_enabled' => filter_var(env('MFA_STEP_UP_ENABLED', true), FILTER_VALIDATE_BOOL),

    // How long a successful two-factor verification counts for step-up.
    'step_up_minutes' => (int) env('MFA_STEP_UP_MINUTES', 10),
];
