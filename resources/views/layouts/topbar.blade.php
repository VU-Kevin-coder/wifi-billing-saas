@php
    $currentTenant = app(\App\Services\Tenancy\TenantContext::class)->getTenant();
    $routeName = request()->route()?->getName() ?? '';
    $isPlatform = str_starts_with($routeName, 'platform.') || !$currentTenant;
    $tenantName = $currentTenant?->name ?? ($isPlatform ? 'Platform Admin' : 'KampalaLink Internet');
    
    $breadcrumbTitle = 'Dashboard';
    if (str_contains($routeName, 'platform.configuration')) {
        $breadcrumbTitle = 'Platform Configuration';
    } elseif (str_contains($routeName, 'platform.billing')) {
        $breadcrumbTitle = 'Platform Billing';
    } elseif (str_contains($routeName, 'tenants')) {
        $breadcrumbTitle = 'Operator Tenants';
    } elseif (str_contains($routeName, 'platform.dashboard')) {
        $breadcrumbTitle = 'Platform Overview';
    } elseif (str_contains($routeName, 'packages')) {
        $breadcrumbTitle = 'Internet Packages';
    } elseif (str_contains($routeName, 'vouchers')) {
        $breadcrumbTitle = 'Vouchers';
    } elseif (str_contains($routeName, 'routers')) {
        $breadcrumbTitle = 'MikroTik routers';
    } elseif (str_contains($routeName, 'customers')) {
        $breadcrumbTitle = 'Customers';
    } elseif (str_contains($routeName, 'settings')) {
        $breadcrumbTitle = 'Captive Portal Settings';
    }
@endphp

<header class="h-16 bg-white border-b border-gray-200/80 px-4 sm:px-8 flex items-center justify-between shrink-0 z-20">
    
    <!-- Left: Sidebar Toggle & Breadcrumbs -->
    <div class="flex items-center gap-2">
        <!-- Desktop Sidebar Toggle Button -->
        <button @click="sidebarCollapsed = !sidebarCollapsed"
                type="button"
                :title="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                class="hidden lg:flex p-1.5 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors focus:outline-none">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 6h16M4 12h16M4 18h16M9 6v12"/>
            </svg>
        </button>

        <!-- Mobile hamburger -->
        <button @click="mobileSidebarOpen = true" 
                class="lg:hidden p-2 rounded-lg text-gray-500 hover:text-gray-700 hover:bg-gray-100 focus:outline-none">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
        </button>

        <!-- Breadcrumbs -->
        <div class="flex items-center text-xs font-medium text-gray-500 ml-1">
            <span class="hover:text-gray-700 cursor-pointer">{{ $tenantName }}</span>
            <svg class="w-3.5 h-3.5 mx-2 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
            <span class="font-semibold text-gray-900">{{ $breadcrumbTitle }}</span>
        </div>
    </div>

    <!-- Right: Search, System Status, Actions -->
    <div class="flex items-center gap-3 sm:gap-4">
        
        <!-- Search Bar with ⌘ K -->
        <div class="hidden md:flex items-center relative w-64 lg:w-72">
            <svg class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <input type="text" 
                   placeholder="Search customers, payments..." 
                   class="w-full pl-9 pr-12 py-1.5 bg-gray-50/70 border border-gray-200 rounded-lg text-xs placeholder-gray-400 focus:bg-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
            <div class="absolute right-2.5 top-1/2 -translate-y-1/2 flex items-center gap-0.5 pointer-events-none">
                <span class="text-[10px] font-mono text-gray-400 bg-gray-200/60 border border-gray-300/60 rounded px-1.5 py-0.5">⌘ K</span>
            </div>
        </div>

        <!-- Systems Live Pill Badge -->
        <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/60">
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
            <span>Systems live</span>
        </div>

        <!-- Help Icon Button -->
        <button type="button" 
                class="w-8 h-8 rounded-full border border-gray-200 flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-50 transition-colors">
            <span class="text-xs font-bold">?</span>
        </button>

        <!-- Notification Bell -->
        <button type="button" 
                class="relative w-8 h-8 rounded-full border border-gray-200 flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-50 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
            </svg>
            <!-- Unread badge -->
            <span class="absolute top-1 right-1 w-2 h-2 rounded-full bg-red-500"></span>
        </button>

    </div>

</header>
