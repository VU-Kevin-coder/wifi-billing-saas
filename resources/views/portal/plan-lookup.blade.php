<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Check my plan · {{ $tenant->settings['portal_title'] ?: $tenant->name }}</title>
    @include('partials.sweetalert-assets')
    @vite('resources/css/public.css')
    @vite('resources/js/public.js')
    <style>
        :root { --paper:#f2eee6; --panel:#fbf9f4; --ink:#1c1a17; --muted:#6f6a60; --line:#ddd6c8; --accent:{{ $tenant->settings['primary_color'] ?? '#3B82F6' }}; --accent-dark:color-mix(in srgb,var(--accent),black 18%); }
        * { box-sizing:border-box; }
        body { min-height:100vh; margin:0; display:grid; place-items:center; padding:24px 16px; background:var(--paper); color:var(--ink); font-family:Inter,ui-sans-serif,system-ui,sans-serif; }
        .portal-card { width:min(100%,440px); padding:28px; border:1px solid var(--line); border-radius:18px; background:var(--panel); box-shadow:0 18px 40px -24px rgba(48,38,24,.4); }
        .portal-brand { display:flex; align-items:center; gap:12px; margin-bottom:26px; }
        .portal-brand-mark { display:grid; width:48px; height:48px; flex:0 0 48px; place-items:center; overflow:hidden; border:1px solid var(--line); border-radius:14px; background:white; color:var(--accent); }
        .portal-brand-mark img { width:100%; height:100%; padding:5px; object-fit:contain; }
        .portal-brand-fallback { width:28px; height:28px; }
        .portal-brand strong, .portal-brand span { display:block; }
        .portal-brand strong { font-family:Georgia,"Times New Roman",serif; font-size:1.12rem; font-weight:600; }
        .portal-brand span { margin-top:3px; color:var(--muted); font-size:.82rem; }
        h1 { margin:0 0 7px; font-family:Georgia,"Times New Roman",serif; font-size:1.8rem; font-weight:500; }
        .lede { margin:0 0 22px; color:var(--muted); font-size:.91rem; line-height:1.5; }
        label { display:block; margin-bottom:7px; color:#3d3932; font-size:.84rem; font-weight:600; }
        input { width:100%; padding:13px 14px; border:1.5px solid var(--line); border-radius:11px; outline:none; background:#fffdf9; font-size:.95rem; }
        input:focus { border-color:var(--accent); box-shadow:0 0 0 4px color-mix(in srgb,var(--accent),transparent 86%); }
        .alert { margin-bottom:16px; padding:11px 13px; border:1px solid #e8b8b2; border-radius:11px; background:#f7e3df; color:#8d2a22; font-size:.84rem; }
        .button { display:block; width:100%; margin-top:14px; padding:13px 16px; border:0; border-radius:11px; background:var(--accent); color:#fff; font-size:.9rem; font-weight:700; cursor:pointer; }
        .button:hover { background:var(--accent-dark); }
        .back { display:block; margin-top:18px; color:var(--accent); font-size:.85rem; font-weight:600; text-align:center; text-decoration:none; }
        .back:hover { text-decoration:underline; text-underline-offset:3px; }
    </style>
</head>
<body>
    <main class="portal-card">
        @include('portal.partials.brand')
        <h1>Check my plan</h1>
        <p class="lede">Enter the voucher code you used to connect. We’ll find the matching plan so you can check its status or renew.</p>

        @if ($errors->any())
            <p class="alert" role="alert">{{ $errors->first() }}</p>
        @endif

        <form method="POST" action="{{ route('portal.plan.lookup', ['tenant' => $tenant->slug], false) }}">
            @csrf
            <label for="voucher_code">Your voucher code</label>
            <input id="voucher_code" name="voucher_code" type="text" required maxlength="64" autocomplete="off" autocapitalize="characters" value="{{ old('voucher_code') }}" placeholder="Enter your voucher code">
            <button type="submit" class="button">Find my plan</button>
        </form>

        <a href="{{ route('portal.index', ['tenant' => $tenant->slug], false) }}" class="back">Back to Wi-Fi packages</a>
    </main>
    @include('partials.swal-flash')
    <x-ui.toast :show-initial="false" />
</body>
</html>
