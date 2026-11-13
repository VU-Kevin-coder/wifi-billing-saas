<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>My plan · {{ $tenant->settings['portal_title'] ?: $tenant->name }}</title>
    @include('partials.sweetalert-assets')
    @vite('resources/css/public.css')
    @vite('resources/js/public.js')
    <style>
        :root { --paper:#f2eee6; --panel:#fbf9f4; --ink:#1c1a17; --muted:#6f6a60; --line:#ddd6c8; --accent:{{ $tenant->settings['primary_color'] ?? '#3B82F6' }}; --accent-dark:color-mix(in srgb,var(--accent),black 18%); --accent-soft:color-mix(in srgb,var(--accent),white 92%); }
        * { box-sizing:border-box; }
        body { min-height:100vh; margin:0; display:grid; place-items:center; padding:24px 16px; background:var(--paper); color:var(--ink); font-family:Inter,ui-sans-serif,system-ui,sans-serif; }
        .portal-card { width:min(100%,440px); padding:28px; border:1px solid var(--line); border-radius:18px; background:var(--panel); box-shadow:0 18px 40px -24px rgba(48,38,24,.4); }
        .portal-brand { display:flex; align-items:center; gap:12px; margin-bottom:24px; }
        .portal-brand-mark { display:grid; width:48px; height:48px; flex:0 0 48px; place-items:center; overflow:hidden; border:1px solid var(--line); border-radius:14px; background:white; color:var(--accent); }
        .portal-brand-mark img { width:100%; height:100%; padding:5px; object-fit:contain; }
        .portal-brand-fallback { width:28px; height:28px; }
        .portal-brand strong, .portal-brand span { display:block; }
        .portal-brand strong { font-family:Georgia,"Times New Roman",serif; font-size:1.12rem; font-weight:600; }
        .portal-brand span { margin-top:3px; color:var(--muted); font-size:.82rem; }
        .plan-card { padding:17px; border:1px solid var(--line); border-radius:13px; background:#fffdf9; }
        .plan-name { margin:0; font-size:1rem; font-weight:700; }
        .status { margin:8px 0 0; color:#146044; font-size:.83rem; font-weight:700; }
        .status.expired { color:var(--muted); }
        .detail { margin:6px 0 0; color:var(--muted); font-size:.83rem; line-height:1.5; }
        .form { margin-top:17px; }
        label { display:block; margin-bottom:7px; color:#3d3932; font-size:.83rem; font-weight:600; }
        input { width:100%; padding:12px 13px; border:1.5px solid var(--line); border-radius:10px; outline:none; background:#fffdf9; font-size:.92rem; }
        input:focus { border-color:var(--accent); box-shadow:0 0 0 4px color-mix(in srgb,var(--accent),transparent 86%); }
        .button { display:block; width:100%; margin-top:12px; padding:13px 15px; border:0; border-radius:10px; background:var(--accent); color:#fff; font-size:.88rem; font-weight:700; text-align:center; text-decoration:none; cursor:pointer; }
        .button:hover { background:var(--accent-dark); }
        .back { display:block; margin-top:18px; color:var(--accent); font-size:.84rem; font-weight:600; text-align:center; text-decoration:none; }
        .back:hover { text-decoration:underline; text-underline-offset:3px; }
    </style>
</head>
<body>
    <main class="portal-card">
        @include('portal.partials.brand')

        <section class="plan-card" aria-label="Plan status">
            <p class="plan-name">{{ $package?->name ?? 'Internet package' }}</p>
            @if ($active)
                <p class="status">Active</p>
                <p class="detail">
                    @if ($minutesLeft >= 90)
                        About {{ round($minutesLeft / 60) }} hours left
                    @else
                        {{ $minutesLeft }} minute{{ $minutesLeft === 1 ? '' : 's' }} left
                    @endif
                </p>
            @else
                <p class="status expired">Expired</p>
            @endif
            @if ($expiresAt)
                <p class="detail">{{ $active ? 'Ends' : 'Ended' }} {{ $expiresAt->format('d M Y, H:i') }}</p>
            @endif
            @if ($package && $package->data_limit && strtolower(trim($package->data_limit)) !== 'unlimited')
                <p class="detail">Data allowance: {{ $package->data_limit }}@if ($bytesUsed > 0) (used {{ number_format($bytesUsed / 1073741824, 2) }} GB)@endif</p>
            @endif
        </section>

        @if ($canRenew)
            <form method="POST" action="{{ route('portal.purchase', ['tenant' => $tenant->slug], false) }}" class="form">
                @csrf
                <input type="hidden" name="_idempotency_key" value="{{ $purchaseKey }}">
                <input type="hidden" name="package_id" value="{{ $package->id }}">
                <label for="phone">Mobile money number</label>
                <input id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" required minlength="9" maxlength="15" value="{{ $phone }}">
                <button type="submit" class="button">{{ $active ? 'Renew now' : 'Buy again' }} · {{ $currency }} {{ number_format((float) $package->price, 0) }}</button>
            </form>
        @else
            <p class="detail" style="margin-top:16px">This package is no longer offered.</p>
            <a href="{{ route('portal.index', ['tenant' => $tenant->slug], false) }}" class="button">See available packages</a>
        @endif

        <a href="{{ route('portal.index', ['tenant' => $tenant->slug], false) }}" class="back">Back to Wi-Fi portal</a>
    </main>
    @include('partials.swal-flash')
    <x-ui.toast :show-initial="false" />
</body>
</html>
