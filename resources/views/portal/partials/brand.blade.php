<div class="portal-brand">
    <div class="portal-brand-mark">
        @if (filled($tenant->settings['logo_path']))
            <img src="{{ asset('storage/'.ltrim($tenant->settings['logo_path'], '/')) }}"
                 alt="{{ $tenant->settings['brand_name'] }} logo"
                 onerror="this.hidden=true; this.nextElementSibling.hidden=false">
            <svg class="portal-brand-fallback" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" aria-hidden="true" hidden>
                <path d="M6 17.5a26 26 0 0 1 36 0M11.5 23.5a18 18 0 0 1 25 0M17 29.5a10 10 0 0 1 14 0"/>
                <circle cx="24" cy="36" r="2.8" fill="currentColor" stroke="none"/>
            </svg>
        @else
            <svg class="portal-brand-fallback" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" aria-hidden="true">
                <path d="M6 17.5a26 26 0 0 1 36 0M11.5 23.5a18 18 0 0 1 25 0M17 29.5a10 10 0 0 1 14 0"/>
                <circle cx="24" cy="36" r="2.8" fill="currentColor" stroke="none"/>
            </svg>
        @endif
    </div>
    <div>
        <strong>{{ $tenant->settings['portal_title'] ?: $tenant->name }}</strong>
        <span>{{ $tenant->settings['portal_subtitle'] ?: 'Connect to the internet' }}</span>
    </div>
</div>
