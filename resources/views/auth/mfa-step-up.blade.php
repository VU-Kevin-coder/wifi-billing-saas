@include('auth.partials.mfa-verification', [
    'pageTitle' => 'Confirm your identity',
    'stepLabel' => 'Security check',
    'submitLabel' => 'Confirm and continue',
    'signOutLabel' => 'Sign out',
    'formAction' => route('mfa.step-up.verify'),
    'totpUrl' => route('mfa.step-up'),
    'recoveryUrl' => route('mfa.step-up', ['mode' => 'recovery']),
])
