<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-[#f8fafc]">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'soma') }} — Hotspot & Billing</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased h-full text-gray-900 bg-[#f8fafc]" 
          x-data="{ sidebarCollapsed: false, mobileSidebarOpen: false }">
        
        <div class="min-h-screen flex h-full">

            <!-- Sidebar (Visible by default) -->
            <div class="flex shrink-0">
                @include('layouts.sidebar')
            </div>

            <!-- Mobile Drawer Sidebar (slide-over) -->
            <div x-show="mobileSidebarOpen" class="relative z-50 lg:hidden" role="dialog" aria-modal="true" x-cloak>
                <div x-show="mobileSidebarOpen" 
                     x-transition:enter="transition-opacity ease-linear duration-300"
                     x-transition:enter-start="opacity-0"
                     x-transition:enter-end="opacity-100"
                     x-transition:leave="transition-opacity ease-linear duration-300"
                     x-transition:leave-start="opacity-100"
                     x-transition:leave-end="opacity-0"
                     class="fixed inset-0 bg-gray-900/60 backdrop-blur-xs" 
                     @click="mobileSidebarOpen = false"></div>

                <div class="fixed inset-0 flex">
                    <div x-show="mobileSidebarOpen" 
                         x-transition:enter="transition ease-in-out duration-300 transform"
                         x-transition:enter-start="-translate-x-full"
                         x-transition:enter-end="translate-x-0"
                         x-transition:leave="transition ease-in-out duration-300 transform"
                         x-transition:leave-start="translate-x-0"
                         x-transition:leave-end="-translate-x-full"
                         class="relative mr-16 flex w-full max-w-xs flex-1">
                        @include('layouts.sidebar')
                    </div>
                </div>
            </div>

            <!-- Main Content Area -->
            <div class="flex-1 flex flex-col min-w-0 h-full overflow-y-auto">
                
                <!-- Impersonation Banner if active -->
                @if(session()->has('impersonate_tenant'))
                    <div class="bg-amber-500 text-white text-center py-2 px-4 text-xs font-bold shrink-0 flex items-center justify-center gap-2">
                        <span>⚠️ You are impersonating this operator workspace as a Platform Admin.</span>
                        <a href="{{ route('platform.stop-impersonate') }}" class="underline bg-amber-600 px-2 py-0.5 rounded hover:bg-amber-700 transition-colors">Exit</a>
                    </div>
                @endif

                <!-- Topbar -->
                @include('layouts.topbar')

                <!-- Main Page Slot -->
                <main class="flex-1">
                    {{ $slot }}
                </main>
            </div>

        </div>

        @stack('scripts')
    </body>

</html>
