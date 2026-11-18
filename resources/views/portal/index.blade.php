<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $tenant->settings['portal_title'] ?: $tenant->name }} · Wi-Fi</title>
    @include('partials.sweetalert-assets')
    @vite('resources/css/public.css')
    @vite('resources/js/public.js')
    <style>
        :root {
            color-scheme: light;
            --paper: #f2eee6;
            --panel: #fbf9f4;
            --field: #fffdf9;
            --ink: #1c1a17;
            --ink-soft: #3d3932;
            --muted: #6f6a60;
            --line: #ddd6c8;
            --accent: {{ $tenant->settings['primary_color'] ?? '#3B82F6' }};
            --accent-dark: color-mix(in srgb, var(--accent), black 18%);
            --accent-soft: color-mix(in srgb, var(--accent), white 90%);
        }
        * { box-sizing: border-box; }
        [x-cloak], [hidden] { display: none !important; }
        body { min-height: 100vh; margin: 0; background: var(--paper); color: var(--ink); font-family: Inter, ui-sans-serif, system-ui, sans-serif; -webkit-font-smoothing: antialiased; }
        button, input { font: inherit; }
        .portal-shell { position: relative; display: grid; min-height: 100vh; place-items: center; overflow: hidden; padding: 28px 16px; }
        .portal-orbit { position: absolute; width: min(80vw, 820px); aspect-ratio: 1; border: 1px solid color-mix(in srgb, var(--accent), transparent 89%); border-radius: 50%; pointer-events: none; }
        .portal-orbit::before, .portal-orbit::after { position: absolute; inset: 9%; border: inherit; border-radius: inherit; content: ""; }
        .portal-orbit::after { inset: 18%; }
        .portal-panel { position: relative; width: min(100%, 470px); overflow: hidden; border: 1px solid var(--line); border-radius: 20px; background: var(--panel); box-shadow: 0 1px 0 rgba(255,255,255,.82) inset, 0 22px 55px -28px rgba(48,38,24,.4); animation: portal-rise .55s cubic-bezier(.22,.9,.3,1) both; }
        @keyframes portal-rise { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
        .portal-hero { position: relative; overflow: hidden; padding: 26px 24px 24px; background: linear-gradient(145deg, var(--accent), var(--accent-dark)); color: white; text-align: center; }
        .portal-hero::after { position: absolute; top: -100px; right: -80px; width: 260px; aspect-ratio: 1; border: 1px solid rgba(255,255,255,.16); border-radius: 50%; box-shadow: 0 0 0 22px rgba(255,255,255,.045), 0 0 0 48px rgba(255,255,255,.035); content: ""; }
        .portal-logo { position: relative; z-index: 1; display: grid; width: 58px; height: 58px; place-items: center; margin: 0 auto 12px; overflow: hidden; border: 1px solid rgba(255,255,255,.5); border-radius: 17px; background: rgba(255,255,255,.96); box-shadow: 0 5px 16px rgba(0,0,0,.12); }
        .portal-logo img { width: 100%; height: 100%; padding: 6px; object-fit: contain; }
        .portal-logo-fallback { width: 32px; height: 32px; color: var(--accent); }
        .portal-hero h1 { position: relative; z-index: 1; margin: 0; font-family: Georgia, "Times New Roman", serif; font-size: 1.8rem; font-weight: 500; letter-spacing: -.025em; line-height: 1.15; }
        .portal-hero p { position: relative; z-index: 1; margin: 6px 0 0; color: rgba(255,255,255,.84); font-size: .9rem; }
        .portal-content { padding: 23px 26px 20px; }
        .portal-alert { margin-bottom: 16px; padding: 11px 13px; border: 1px solid #e8b8b2; border-radius: 11px; background: #f7e3df; color: #8d2a22; font-size: .84rem; line-height: 1.45; }
        .portal-alert p { margin: 0; }
        .portal-alert p + p { margin-top: 4px; }
        .section-label { display: flex; align-items: center; gap: 12px; margin: 4px 0 13px; color: var(--muted); font-size: .72rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
        .section-label::before, .section-label::after { height: 1px; flex: 1; background: var(--line); content: ""; }
        .portal-field { width: 100%; min-width: 0; padding: 13px 14px; border: 1.5px solid var(--line); border-radius: 11px; outline: none; background: var(--field); color: var(--ink); font-size: .93rem; transition: border-color .2s, box-shadow .2s; }
        .portal-field::placeholder { color: #a39b8d; }
        .portal-field:focus { border-color: var(--accent); box-shadow: 0 0 0 4px color-mix(in srgb, var(--accent), transparent 86%); }
        .voucher-input { display: flex; align-items: center; gap: 10px; }
        .voucher-input svg { width: 19px; height: 19px; flex: 0 0 auto; color: var(--muted); }
        .voucher-input .portal-field { border: 0; background: transparent; box-shadow: none; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 1rem; letter-spacing: .06em; }
        .voucher-box { display: flex; min-height: 50px; align-items: center; gap: 8px; padding: 0 12px; border: 1.5px solid var(--line); border-radius: 12px; background: var(--field); transition: border-color .2s, box-shadow .2s; }
        .voucher-box:focus-within { border-color: var(--accent); box-shadow: 0 0 0 4px color-mix(in srgb, var(--accent), transparent 86%); }
        .voucher-box > svg { width: 20px; height: 20px; flex: 0 0 20px; color: var(--muted); }
        .voucher-box .portal-field { width: 0; min-width: 0; flex: 1 1 auto; padding: 12px 4px; border: 0; background: transparent; box-shadow: none; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .95rem; letter-spacing: .04em; }
        .voucher-box .portal-field:focus { box-shadow: none; }
        .portal-button { display: inline-flex; min-height: 44px; align-items: center; justify-content: center; gap: 8px; padding: 11px 16px; border: 0; border-radius: 11px; background: var(--accent); color: white; font-size: .88rem; font-weight: 700; text-decoration: none; cursor: pointer; transition: background .2s, transform .15s; }
        .portal-button:hover { background: var(--accent-dark); }
        .portal-button:active { transform: scale(.99); }
        .portal-button.full { width: 100%; margin-top: 11px; }
        .portal-button.small { min-height: 36px; padding: 8px 14px; border-radius: 9px; font-size: .76rem; }
        .plans { display: grid; gap: 9px; }
        .plan-card { overflow: hidden; border: 1.5px solid var(--line); border-radius: 13px; background: var(--field); transition: border-color .2s, box-shadow .2s, transform .2s; }
        .plan-card:hover { border-color: color-mix(in srgb, var(--accent), var(--line) 45%); transform: translateY(-1px); }
        .plan-row { display: flex; align-items: center; gap: 12px; padding: 13px 14px; }
        .plan-copy { min-width: 0; flex: 1; }
        .plan-copy strong { display: block; overflow-wrap: anywhere; color: var(--ink-soft); font-size: .91rem; font-weight: 700; }
        .plan-copy small { display: block; margin-top: 3px; color: var(--muted); font-size: .78rem; }
        .plan-price { color: var(--ink); font-family: Georgia, "Times New Roman", serif; font-size: 1.04rem; font-weight: 600; white-space: nowrap; }
        .purchase-form { padding: 14px; border-top: 1px solid var(--line); background: var(--accent-soft); }
        .purchase-form label { display: block; margin-bottom: 6px; color: var(--ink-soft); font-size: .75rem; font-weight: 600; }
        .purchase-form .portal-field { padding: 11px 12px; font-size: .87rem; }
        .purchase-form .portal-field + label { margin-top: 11px; }
        .purchase-note { margin: 8px 0 0; color: var(--muted); font-size: .72rem; line-height: 1.45; }
        .empty-plans { padding: 16px; border: 1px dashed var(--line); border-radius: 12px; color: var(--muted); font-size: .84rem; text-align: center; }
        .portal-links { display: grid; gap: 8px; margin-top: 18px; padding-top: 15px; border-top: 1px solid var(--line); text-align: center; }
        .portal-link { color: var(--accent); font-size: .84rem; font-weight: 650; text-decoration: none; }
        .portal-link:hover { text-decoration: underline; text-underline-offset: 3px; }
        .support { margin-top: 17px; padding: 13px; border-radius: 12px; background: color-mix(in srgb, var(--accent), white 94%); color: var(--muted); font-size: .78rem; line-height: 1.5; text-align: center; }
        .support a { color: var(--accent); font-weight: 700; text-decoration: none; }
        .support a:hover { text-decoration: underline; }
        .help-toggle { display: flex; width: 100%; align-items: center; justify-content: space-between; margin-top: 14px; padding: 10px 0 0; border: 0; border-top: 1px solid var(--line); background: transparent; color: var(--muted); font-size: .8rem; text-align: left; cursor: pointer; }
        .help-copy { padding-top: 8px; color: var(--muted); font-size: .78rem; line-height: 1.55; }
        .portal-foot { margin: 17px 0 0; color: var(--muted); font-size: .7rem; text-align: center; }
        @media (max-width: 480px) {
            .portal-content { padding: 20px 18px 18px; }
            .portal-hero { padding: 23px 18px 21px; }
            .portal-hero h1 { font-size: 1.62rem; }
            .plan-row { gap: 8px; padding: 12px 10px; }
            .plan-price { font-size: .92rem; }
            .portal-button.small { padding-inline: 10px; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { scroll-behavior: auto !important; animation-duration: .01ms !important; transition-duration: .01ms !important; }
        }
    </style>
</head>
<body x-data="{ buyingPackageId: null, helpOpen: false }">
<main class="portal-shell">
    <div class="portal-orbit" aria-hidden="true"></div>
    <section class="portal-panel" aria-label="Guest Wi-Fi portal">
        <header class="portal-hero">
            <div class="portal-logo">
                @if (filled($tenant->settings['logo_path']))
                    <img src="{{ asset('storage/'.ltrim($tenant->settings['logo_path'], '/')) }}"
                         alt="{{ $tenant->settings['brand_name'] }} logo"
                         onerror="this.hidden=true; this.nextElementSibling.hidden=false">
                    <svg class="portal-logo-fallback" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" aria-hidden="true" hidden>
                        <path d="M6 17.5a26 26 0 0 1 36 0M11.5 23.5a18 18 0 0 1 25 0M17 29.5a10 10 0 0 1 14 0"/>
                        <circle cx="24" cy="36" r="2.8" fill="currentColor" stroke="none"/>
                    </svg>
                @else
                    <svg class="portal-logo-fallback" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" aria-hidden="true">
                        <path d="M6 17.5a26 26 0 0 1 36 0M11.5 23.5a18 18 0 0 1 25 0M17 29.5a10 10 0 0 1 14 0"/>
                        <circle cx="24" cy="36" r="2.8" fill="currentColor" stroke="none"/>
                    </svg>
                @endif
            </div>
            <h1>{{ $tenant->settings['portal_title'] ?: $tenant->name }}</h1>
            <p>{{ $tenant->settings['portal_subtitle'] ?: 'Connect to the internet' }}</p>
        </header>

        <div class="portal-content">
            @if (session('status'))
                <div class="portal-alert" role="status"><p>{{ session('status') }}</p></div>
            @endif
            @if ($errors->any())
                <div class="portal-alert" role="alert">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <section aria-labelledby="voucher-heading">
                <h2 id="voucher-heading" class="section-label">Have a voucher?</h2>
                <form id="voucher-form" method="POST" action="{{ route('portal.redeem', ['tenant' => $tenant->slug], false) }}">
                    @csrf
                    @if ($routerId)
                        <input type="hidden" name="router_id" value="{{ $routerId }}">
                    @endif
                    @if ($routerIdentity)
                        <input type="hidden" name="router" value="{{ $routerIdentity }}">
                    @endif
                    <div class="voucher-box">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M4 5h16a2 2 0 0 1 2 2v3a2 2 0 0 0 0 4v3a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-3a2 2 0 0 0 0-4V7a2 2 0 0 1 2-2Z"/>
                            <path d="M13 5v2m0 4v2m0 4v2"/>
                        </svg>
                        <input id="voucher_code" class="portal-field" name="voucher_code" type="text" required maxlength="64" autocomplete="off" autocapitalize="characters" value="{{ old('voucher_code') }}" placeholder="Enter your voucher code" aria-label="Voucher code">
                    </div>
                    <button class="portal-button full" type="submit" data-loading-text="Checking voucher…">Connect to Wi-Fi</button>
                    <p id="voucher-status" role="status" aria-live="polite" aria-atomic="true"></p>
                </form>
            </section>

            @if ($tenant->settings['show_packages'] ?? true)
                <section aria-labelledby="packages-heading">
                    <h2 id="packages-heading" class="section-label" style="margin-top:24px">Or purchase a package</h2>
                    <div class="plans">
                        @forelse ($packages as $pkg)
                            <article class="plan-card">
                                <div class="plan-row" x-show="buyingPackageId !== {{ $pkg->id }}">
                                    <div class="plan-copy">
                                        <strong>{{ $pkg->name }}</strong>
                                        <small>{{ $pkg->duration_value }} {{ $pkg->duration_unit }}</small>
                                    </div>
                                    <span class="plan-price">{{ $pkg->currency ?: $currency }} {{ number_format((float) $pkg->price, 0) }}</span>
                                    <button type="button" class="portal-button small" @click="buyingPackageId = {{ $pkg->id }}">Buy</button>
                                </div>

                                <div class="purchase-form" x-show="buyingPackageId === {{ $pkg->id }}" x-cloak>
                                    <form method="POST" action="{{ route('portal.purchase', ['tenant' => $tenant->slug], false) }}">
                                        @csrf
                                        <input type="hidden" name="_idempotency_key" value="{{ $purchaseKeys[$pkg->id] }}">
                                        <input type="hidden" name="package_id" value="{{ $pkg->id }}">
                                        @if ($routerId)
                                            <input type="hidden" name="router_id" value="{{ $routerId }}">
                                        @endif
                                        @if ($routerIdentity)
                                            <input type="hidden" name="router" value="{{ $routerIdentity }}">
                                        @endif
                                        <label for="phone-{{ $pkg->id }}">Mobile money number</label>
                                        <input id="phone-{{ $pkg->id }}" class="portal-field" type="tel" name="phone" inputmode="tel" autocomplete="tel" required minlength="9" maxlength="15" value="{{ old('phone') }}" placeholder="e.g. 077 123 4567">
                                        <label for="email-{{ $pkg->id }}">Email (optional)</label>
                                        <input id="email-{{ $pkg->id }}" class="portal-field" type="email" name="email" autocomplete="email" value="{{ old('email') }}" placeholder="you@example.com">
                                        <p class="purchase-note">A payment prompt will be sent to this number. Your Wi-Fi access is issued after payment is confirmed.</p>
                                        <div style="display:flex;gap:8px;margin-top:12px">
                                            <button class="portal-button" type="submit" data-loading-text="Submitting payment…" style="flex:1">Pay {{ $pkg->currency ?: $currency }} {{ number_format((float) $pkg->price, 0) }}</button>
                                            <button class="portal-button small" type="button" @click="buyingPackageId = null" style="background:transparent;color:var(--ink-soft);border:1px solid var(--line)">Cancel</button>
                                        </div>
                                    </form>
                                </div>
                            </article>
                        @empty
                            <p class="empty-plans">No packages are currently available. If you have a voucher, enter it above; otherwise contact the network operator for help.</p>
                        @endforelse
                    </div>
                </section>
            @endif

            <div class="portal-links">
                <a class="portal-link" href="{{ route('portal.plan', ['tenant' => $tenant->slug], false) }}">Already bought? Check your plan</a>
            </div>

            @if (filled($tenant->settings['support_phone']))
                <div class="support">
                    Need help? Call
                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $tenant->settings['support_phone']) }}">{{ $tenant->settings['support_phone'] }}</a>
                </div>
            @endif

            @if ($tenant->settings['show_troubleshooting'] ?? true)
                <button type="button" class="help-toggle" @click="helpOpen = !helpOpen" :aria-expanded="helpOpen.toString()">
                    <span>Having trouble connecting?</span>
                    <span aria-hidden="true" x-text="helpOpen ? '−' : '+'"></span>
                </button>
                <p class="help-copy" x-show="helpOpen" x-cloak>Check that Wi-Fi is enabled on your device, then enter your voucher code above. If you still need help, contact the network operator.</p>
            @endif

            <p class="portal-foot">Powered by {{ config('app.name', 'Hamnet') }} · {{ date('Y') }}</p>
        </div>
    </section>
</main>
<script>
    (() => {
        const form = document.getElementById('voucher-form');
        const status = document.getElementById('voucher-status');
        const clearStatus = () => { status.textContent = ''; };
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) return;
            // Wait until every submit handler has had a chance to cancel navigation.
            queueMicrotask(() => {
                if (!event.defaultPrevented) status.textContent = 'Checking voucher…';
            });
        });
        form.addEventListener('ui:form-restored', clearStatus);
        window.addEventListener('pageshow', clearStatus);
    })();
</script>
@include('partials.swal-flash')
<x-ui.toast :show-initial="false" />
</body>
</html>
