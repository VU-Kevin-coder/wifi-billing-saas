@include('auth.partials.mfa-verification', [
    'pageTitle' => 'Verify your identity',
    'stepLabel' => 'Sign-in · step 2 of 2',
    'submitLabel' => 'Verify and continue',
    'signOutLabel' => 'Sign out and use a different account',
    'formAction' => route('two-factor.verify'),
    'totpUrl' => route('two-factor.challenge'),
    'recoveryUrl' => route('two-factor.challenge', ['mode' => 'recovery']),
])
