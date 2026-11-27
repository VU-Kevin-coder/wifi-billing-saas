<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Hamnet') }} | {{ $title ?? 'Account' }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Space+Grotesk:wght@500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

    @include('partials.sweetalert-assets')
    @vite(['resources/css/public.css', 'resources/css/auth-design.css'])
    @vite('resources/js/public.js')
</head>
<body>
<main class="shell">
    <aside class="brand-panel">
        <a class="logo" href="{{ route('landing') }}">
            <span class="logo-mark"><i class="bi bi-broadcast"></i></span>
            Hamnet
        </a>

        <div class="bp-body">
            <h2>{{ $title ?? 'Your Wi-Fi business, always in your control.' }}</h2>
            <p>{{ $subtitle ?? 'Manage your routers, customers, and revenue from one secure workspace.' }}</p>
            <ul class="points">
                <li><i class="bi bi-shield-lock"></i> Secure sign-in and account protection</li>
                <li><i class="bi bi-router"></i> Router and hotspot management</li>
                <li><i class="bi bi-graph-up-arrow"></i> Billing and network operations in one place</li>
            </ul>
            <div class="status">
                <div><span class="dot"></span>Hamnet Cloud</div>
                <div class="ok">✓ Secure account access</div>
                <div class="ok">✓ Operator workspace tools</div>
            </div>
        </div>

        <div class="bp-foot">&copy; {{ date('Y') }} Hamnet Systems. All rights reserved.</div>
    </aside>

    <section class="form-panel">
        <div class="form-wrap">
            <div class="top-row">
                <a class="logo" href="{{ route('landing') }}">
                    <span class="logo-mark"><i class="bi bi-broadcast"></i></span>
                    Hamnet
                </a>
                <a class="back-home" href="{{ route('landing') }}"><i class="bi bi-arrow-left"></i> Back to home</a>
            </div>

            <div class="card auth-card">
                {{ $slot }}
            </div>
        </div>
    </section>
</main>

@include('partials.swal-flash')
<x-ui.toast :show-initial="false" />
<script>
document.querySelectorAll('[data-eye]').forEach((button) => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.eye);
        const icon = button.querySelector('i');
        const show = input.type === 'password';

        input.type = show ? 'text' : 'password';
        button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        icon.className = `bi ${show ? 'bi-eye-slash' : 'bi-eye'}`;
    });
});
</script>
</body>
</html>
