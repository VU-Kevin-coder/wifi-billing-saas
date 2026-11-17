@php
    $flashMessages = array_filter([
        'success' => session('success'),
        // A status can be neutral or mixed (for example, a partial failure).
        'status' => session('status'),
        'warning' => session('warning'),
        'error' => session('error'),
        'validation' => $errors->any() ? $errors->first() : null,
    ], fn ($message) => is_string($message) && $message !== '');

    // Only suppress a type when that exact page always renders it visibly itself.
    // Keep this explicit: page text comparisons can suppress unrelated messages.
    $pageOwnedFeedback = [
        'success' => ['platform.tenants.index', 'platform.billing.index'],
        'status' => [
            'platform.tenants.show', 'platform.vpn-peers.index', 'platform.revenue.index',
            'platform.revenue.adjustments.index', 'platform.configuration.control', 'platform.policies.index',
            'platform.refunds.index', 'profile.security.index', 'operator.wallet.index',
            'operator.settings.index', 'operator.settings.router', 'operator.team.index', 'operator.staff.index',
            'operator.customers.index', 'operator.sites.index', 'operator.vouchers.index',
            'operator.routers.index', 'operator.routers.show', 'operator.packages.index', 'operator.refunds.index',
        ],
        'error' => ['platform.revenue.adjustments.index', 'platform.configuration.index', 'platform.billing.index', 'profile.security.index'],
        'validation' => [
            'platform.tenants.show', 'platform.revenue.index', 'platform.configuration.index',
            'platform.configuration.control', 'platform.policies.index', 'platform.refunds.index',
            'profile.security.index', 'operator.wallet.index', 'operator.settings.index', 'operator.staff.index',
            'operator.sites.index', 'operator.routers.index', 'operator.refunds.index',
        ],
    ];
    foreach ($pageOwnedFeedback as $type => $routes) {
        if (request()->routeIs(...$routes)) unset($flashMessages[$type]);
    }
    // The configuration page displays success in preference to status when both exist.
    if (request()->routeIs('platform.configuration.index')) {
        if (session('success')) unset($flashMessages['success']);
        else unset($flashMessages['status']);
    }

    $flashMessages = array_map(fn ($message) => match ($message) {
        'verification-link-sent' => __('A new verification link has been sent to your email address.'),
        'profile-updated' => __('Profile updated successfully.'),
        'password-updated' => __('Password updated successfully.'),
        default => $message,
    }, $flashMessages);
@endphp

@if($flashMessages)
    {{-- Server-rendered feedback is the primary notification, not a second modal.
         Keep it readable without JS; the local stylesheet positions it prominently. --}}
    <details class="ui-flash" open>
        <summary>Notifications</summary>
        @foreach($flashMessages as $type => $message)
            <div class="ui-flash-message" data-flash-type="{{ $type }}" data-flash-message="{{ $message }}" role="{{ in_array($type, ['error', 'validation'], true) ? 'alert' : 'status' }}">
                <strong>{{ ucfirst($type) }}:</strong> {{ $message }}
            </div>
        @endforeach
    </details>
@endif
