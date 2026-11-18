<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-[#f8fafc]">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'soma') }} — Hotspot & Billing</title>
        <script>
            (() => {
                let preference = 'auto';
                try {
                    preference = localStorage.getItem('wifi-billing-theme') || 'auto';
                } catch (_) {}
                const root = document.documentElement;
                if (preference === 'light' || preference === 'dark') root.dataset.appTheme = preference;
                const dark = preference === 'dark'
                    || (preference === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                root.classList.toggle('dark', dark);
            })();
        </script>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @auth
            @include('partials.sweetalert-assets')
        @endauth
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <style>
            [x-cloak] { display: none !important; }
            .navigation-fallback, .js-navigation-control { display: none; }
            body.navigation-enhanced .js-navigation-control { display: flex; }
            body.navigation-enhanced .navigation-account-fallback { display: none; }
            body:not(.navigation-enhanced) [x-show="sidebarCollapsed"] { display: none; }
            body:not(.navigation-enhanced) .desktop-sidebar aside, .navigation-fallback aside { width: 16rem; }
            header :focus-visible, aside :focus-visible, .navigation-fallback summary:focus-visible {
                outline: 3px solid #2563eb !important; outline-offset: 2px;
            }
            @media (max-width: 1023px) {
                body:not(.navigation-enhanced) .navigation-fallback { display: block; }
                body.navigation-enhanced .js-desktop-navigation-control { display: none; }
                body:not(.navigation-enhanced) .desktop-sidebar { display: none; }
            }
            @media (min-width: 1024px) {
                body:not(.navigation-enhanced) .desktop-sidebar { display: flex; }
                body.navigation-enhanced .js-mobile-navigation-control { display: none; }
            }
        </style>
    </head>
    <body class="font-sans antialiased h-full text-gray-900 bg-[#f8fafc]" 
          x-data="mobileSidebar"
          x-init="document.body.classList.add('navigation-enhanced')"
          @keydown.escape.window="closeSidebar()"
          @focusin.window="keepSidebarFocus($event)"
          @resize.window="closeSidebarOnDesktop()">
        
        <div class="min-h-screen flex h-full">

            <!-- Sidebar (Visible by default) -->
            <div class="desktop-sidebar hidden lg:flex shrink-0" :inert="mobileSidebarOpen ? '' : null">
                @include('layouts.sidebar')
            </div>

            <!-- Mobile Drawer Sidebar (slide-over) -->
            <div id="mobile-navigation" x-ref="sidebarDialog" x-show="mobileSidebarOpen" class="relative z-50 lg:hidden" role="dialog" aria-modal="true" aria-label="Main navigation" @keydown.tab="trapSidebarTab($event)" x-cloak>
                <div x-show="mobileSidebarOpen" 
                     x-transition:enter="transition-opacity ease-linear duration-300"
                     x-transition:enter-start="opacity-0"
                     x-transition:enter-end="opacity-100"
                     x-transition:leave="transition-opacity ease-linear duration-300"
                     x-transition:leave-start="opacity-100"
                     x-transition:leave-end="opacity-0"
                     class="fixed inset-0 bg-gray-900/60 backdrop-blur-xs" 
                     @click="closeSidebar()"></div>

                <div class="fixed inset-0 flex" @click.self="closeSidebar()">
                    <div x-show="mobileSidebarOpen" 
                         x-transition:enter="transition ease-in-out duration-300 transform"
                         x-transition:enter-start="-translate-x-full"
                         x-transition:enter-end="translate-x-0"
                         x-transition:leave="transition ease-in-out duration-300 transform"
                         x-transition:leave-start="translate-x-0"
                         x-transition:leave-end="-translate-x-full"
                         class="relative mr-16 flex w-full max-w-xs flex-1">
                        <button x-ref="sidebarClose" type="button" @click="closeSidebar()" aria-label="Close navigation" class="absolute left-full top-3 ml-2 flex h-11 w-11 items-center justify-center rounded-lg bg-white text-xl text-gray-900 shadow focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-600"><span aria-hidden="true">&times;</span></button>
                        @include('layouts.sidebar')
                    </div>
                </div>
            </div>

            <!-- Main Content Area -->
            <div class="flex-1 flex flex-col min-w-0 h-full overflow-y-auto" :inert="mobileSidebarOpen ? '' : null">
                <details class="navigation-fallback">
                    <summary>Navigation</summary>
                    @include('layouts.sidebar', ['fallback' => true])
                </details>
                
                <!-- Impersonation Banner if active -->
                @if(session()->has('impersonate_tenant'))
                    <div class="bg-amber-500 text-white text-center py-2 px-4 text-xs font-bold shrink-0 flex items-center justify-center gap-2">
                        <span>⚠️ You are impersonating this operator workspace as a Platform Admin.</span>
                        <form method="POST" action="{{ route('platform.stop-impersonate') }}" class="inline">
                            @csrf
                            <button type="submit" class="underline bg-amber-600 px-2 py-0.5 rounded hover:bg-amber-700 transition-colors">Exit</button>
                        </form>
                    </div>
                @endif

                <!-- Topbar -->
                @include('layouts.topbar')

                <!-- Main Page Slot -->
                <main x-ref="mainContent" tabindex="-1" class="flex-1">
                    {{ $slot }}
                </main>
            </div>

        </div>

        @stack('scripts')
        @auth
            @include('partials.swal-flash')
        @endauth
        <x-ui.toast :show-initial="false" />
    </body>

</html>
