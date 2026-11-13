<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle ?? config('app.name', 'Hamnet') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet" />

    @include('partials.sweetalert-assets')
    @vite('resources/css/public.css')
    @vite('resources/js/public.js')
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-gray-50 antialiased text-gray-900">
<div class="min-h-screen flex">

    {{-- ─── Left side: Branding panel (hidden on mobile) ─────────────────── --}}
    <div class="hidden lg:flex lg:w-1/2 bg-blue-600 flex-col justify-between p-12 text-white relative overflow-hidden">

        {{-- Decorative background shape --}}
        <div class="absolute inset-0 opacity-10 pointer-events-none">
            <svg class="absolute left-0 top-0 h-full w-full" viewBox="0 0 100 100" preserveAspectRatio="none">
                <path d="M0 100 C 20 0 50 0 100 100 Z" fill="white" />
            </svg>
        </div>

        {{-- Logo --}}
        <div class="relative z-10 flex items-center gap-3">
            <div class="w-9 h-9 bg-white/20 rounded-xl flex items-center justify-center backdrop-blur-sm">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/>
                </svg>
            </div>
            <span class="text-xl font-bold tracking-tight">Hamnet</span>
        </div>

        {{-- Headline + sub --}}
        <div class="relative z-10 max-w-md">
            <h2 class="text-4xl font-extrabold leading-tight mb-4">
                {{ $title ?? 'Welcome Back.' }}
            </h2>
            <p class="text-blue-100 text-lg leading-relaxed">
                {{ $subtitle ?? 'Manage your Wi-Fi business, generate vouchers, and track your network — all in one place.' }}
            </p>
        </div>

        {{-- Testimonial card --}}
        <div class="relative z-10 bg-white/10 backdrop-blur-md p-6 rounded-2xl border border-white/10">
            <p class="text-sm text-white mb-4 leading-relaxed">
                "Hamnet completely transformed how we manage our ISP. Vouchers are generated in seconds and our customers love the portal."
            </p>
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-blue-400 flex items-center justify-center font-bold text-white text-sm">
                    AM
                </div>
                <div>
                    <p class="text-sm font-bold text-white">Alex M.</p>
                    <p class="text-xs text-blue-200">ISP Operator, Kampala</p>
                </div>
            </div>
        </div>
    </div>

    {{-- ─── Right side: Form panel ─────────────────────────────────────────── --}}
    <div class="w-full lg:w-1/2 flex items-center justify-center p-6 sm:p-12 bg-white">
        <div class="w-full max-w-md">

            {{-- Mobile logo (only visible below lg) --}}
            <div class="lg:hidden flex items-center gap-2 mb-8">
                <div class="w-9 h-9 bg-blue-600 rounded-xl flex items-center justify-center">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/>
                    </svg>
                </div>
                <span class="text-2xl font-bold text-gray-900">Hamnet</span>
            </div>

            {{-- Flash status (e.g. password reset sent) --}}
            @if (session('status'))
                <div class="mb-6 text-sm font-medium text-green-700 bg-green-50 border border-green-200 px-4 py-3 rounded-xl">
                    {{ session('status') }}
                </div>
            @endif

            {{-- Slot: the actual form --}}
            {{ $slot }}

        </div>
    </div>

</div>
@include('partials.swal-flash')
<x-ui.toast :show-initial="false" />
</body>
</html>

