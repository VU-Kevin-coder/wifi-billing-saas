@php
    $currentTenant = app(\App\Services\Tenancy\TenantContext::class)->getTenant();
    $isPlatformAdmin = auth()->user()?->hasRole('platform_admin');
    $isPlatformContext = !$currentTenant;
    
    $tenantName = $currentTenant?->name ?? ($isPlatformAdmin ? 'Platform Admin' : 'KampalaLink Internet');
    $words = explode(' ', $tenantName);
    $monogram = strtoupper(substr($words[0] ?? 'P', 0, 1) . substr($words[1] ?? 'A', 0, 1));
    
    $user = auth()->user();
    $userInitials = 'OP';
    if ($user) {
        $nameParts = explode(' ', $user->name);
        $userInitials = strtoupper(substr($nameParts[0] ?? 'O', 0, 1) . substr($nameParts[1] ?? 'P', 0, 1));
    }
@endphp

<aside :class="{ 'w-64': !sidebarCollapsed, 'w-20': sidebarCollapsed }"
       class="bg-white border-r border-gray-200/80 flex flex-col justify-between shrink-0 transition-all duration-300 select-none z-30">
    
    <!-- Top Brand & Nav -->
    <div class="flex flex-col min-h-0 flex-1 overflow-y-auto">
        
        <!-- Brand Logo Header with Top Collapse Toggle -->
        <div class="h-16 flex items-center justify-between px-4 border-b border-gray-100">
            <div class="flex items-center gap-2.5 min-w-0">
                <div class="w-9 h-9 rounded-xl bg-blue-600 flex items-center justify-center text-white shadow-sm shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"></path>
                    </svg>
                </div>
                <span x-show="!sidebarCollapsed" class="text-2xl font-bold tracking-tight text-gray-900 font-sans">soma</span>
            </div>

            <!-- Top Collapse / Expand Toggle Button -->
            <button @click="sidebarCollapsed = !sidebarCollapsed" 
                    type="button"
                    :title="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                    class="p-1.5 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors focus:outline-none shrink-0">
                <svg x-show="!sidebarCollapsed" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/>
                </svg>
                <svg x-show="sidebarCollapsed" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"/>
                </svg>
            </button>
        </div>

        <!-- Workspace Selector Card -->
        <div class="p-4 pb-2" x-show="!sidebarCollapsed">
            <div class="p-2.5 rounded-xl border border-gray-200/80 bg-gray-50/70 flex items-center justify-between hover:bg-gray-100/70 transition-colors cursor-pointer group">
                <div class="flex items-center gap-2.5 min-w-0">
                    <div class="w-8 h-8 rounded-lg {{ $currentTenant ? 'bg-gray-200/90 text-gray-700' : 'bg-blue-100 text-blue-700' }} font-bold text-xs flex items-center justify-center shrink-0">
                        {{ $monogram }}
                    </div>
                    <div class="min-w-0">
                        <div class="text-xs font-bold text-gray-900 truncate leading-tight">{{ $tenantName }}</div>
                        <div class="text-[11px] text-gray-400 font-medium">
                            {{ $currentTenant ? 'Operator workspace' : 'Platform Administration' }}
                        </div>
                    </div>
                </div>
                <svg class="w-4 h-4 text-gray-400 group-hover:text-gray-600 shrink-0 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"/>
                </svg>
            </div>
        </div>

        <!-- Navigation Menu -->
        <nav class="px-3 py-2 space-y-6">

            @if ($isPlatformAdmin)
            <!-- PLATFORM ADMINISTRATION -->
            <div>
                <p x-show="!sidebarCollapsed" class="text-[10px] font-bold text-gray-400 tracking-wider uppercase px-3 mb-1.5">Platform</p>
                <div class="space-y-0.5">
                    <!-- Operator tenants -->
                    <a href="{{ route('platform.tenants.index') }}" 
                       class="{{ request()->routeIs('platform.tenants.*') ? 'bg-blue-50 text-blue-600 font-semibold' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 font-medium' }} flex items-center gap-3 px-3 py-2 rounded-xl text-xs transition-colors group">
                        <svg class="w-4 h-4 shrink-0 {{ request()->routeIs('platform.tenants.*') ? 'text-blue-600' : 'text-gray-400 group-hover:text-gray-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                        </svg>
                        <span x-show="!sidebarCollapsed">Operator tenants</span>
                    </a>

                    <!-- Platform Billing -->
                    <a href="{{ route('platform.billing.index') }}" 
                       class="{{ request()->routeIs('platform.billing.*') ? 'bg-blue-50 text-blue-600 font-semibold' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 font-medium' }} flex items-center gap-3 px-3 py-2 rounded-xl text-xs transition-colors group">
                        <svg class="w-4 h-4 shrink-0 {{ request()->routeIs('platform.billing.*') ? 'text-blue-600' : 'text-gray-400 group-hover:text-gray-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span x-show="!sidebarCollapsed">Platform billing</span>
                    </a>

                    <!-- Platform Configuration -->
                    <a href="{{ route('platform.configuration.index') }}" 
                       class="{{ request()->routeIs('platform.configuration.*') ? 'bg-blue-50 text-blue-600 font-semibold' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 font-medium' }} flex items-center gap-3 px-3 py-2 rounded-xl text-xs transition-colors group">
                        <svg class="w-4 h-4 shrink-0 {{ request()->routeIs('platform.configuration.*') ? 'text-blue-600' : 'text-gray-400 group-hover:text-gray-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <span x-show="!sidebarCollapsed">Configuration</span>
                    </a>

                    @if($currentTenant)
                    <!-- Return to Platform -->
                    <a href="{{ route('platform.tenants.index') }}" 
                       class="text-gray-600 hover:bg-gray-50 hover:text-gray-900 font-medium flex items-center gap-3 px-3 py-2 rounded-xl text-xs transition-colors group">
                        <svg class="w-4 h-4 shrink-0 text-gray-400 group-hover:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                        </svg>
                        <span x-show="!sidebarCollapsed">Platform Console</span>
                    </a>
                    @endif
                </div>
            </div>
            @endif

            @if ($currentTenant)
            
            <!-- WORKSPACE -->
            <div>
                <p x-show="!sidebarCollapsed" class="text-[10px] font-bold text-gray-400 tracking-wider uppercase px-3 mb-1.5">Workspace</p>
                <div class="space-y-0.5">
                    <!-- Dashboard -->
                    <a href="{{ route('dashboard') }}" 
                       class="{{ request()->routeIs('dashboard', 'operator.dashboard') ? 'bg-blue-50 text-blue-600 font-semibold' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 font-medium' }} flex items-center gap-3 px-3 py-2 rounded-xl text-xs transition-colors group">
                        <svg class="w-4 h-4 shrink-0 {{ request()->routeIs('dashboard', 'operator.dashboard') ? 'text-blue-600' : 'text-gray-400 group-hover:text-gray-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
                        </svg>
                        <span x-show="!sidebarCollapsed">Dashboard</span>
                    </a>

                    <!-- Customers -->
                    <a href="{{ route('operator.customers.index') }}" 
                       class="{{ request()->routeIs('operator.customers.*') ? 'bg-blue-50 text-blue-600 font-semibold' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 font-medium' }} flex items-center gap-3 px-3 py-2 rounded-xl text-xs transition-colors group">
                        <svg class="w-4 h-4 shrink-0 {{ request()->routeIs('operator.customers.*') ? 'text-blue-600' : 'text-gray-400 group-hover:text-gray-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                        <span x-show="!sidebarCollapsed">Customers</span>
                    </a>

                    <!-- SaaS billing -->
                    @if(auth()->user()->hasAnyRole(['operator_admin', 'operator_finance']) || (auth()->user()->hasRole('platform_admin') && session()->has('impersonate_tenant')))
                    <a href="{{ route('operator.billing.index') }}"
                       class="{{ request()->routeIs('operator.billing.*') ? 'bg-blue-50 text-blue-600 font-semibold' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 font-medium' }} flex items-center gap-3 px-3 py-2 rounded-xl text-xs transition-colors group">
                        <svg class="w-4 h-4 shrink-0 text-gray-400 group-hover:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        <span x-show="!sidebarCollapsed">SaaS billing</span>
                    </a>
                    @endif
                </div>
            </div>

            <!-- NETWORK -->
            <div>
                <p x-show="!sidebarCollapsed" class="text-[10px] font-bold text-gray-400 tracking-wider uppercase px-3 mb-1.5">Network</p>
                <div class="space-y-0.5">
                    <!-- MikroTik routers -->
                    <a href="{{ route('operator.routers.index') }}" 
                       class="{{ request()->routeIs('operator.routers.*') ? 'bg-blue-50 text-blue-600 font-semibold' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 font-medium' }} flex items-center gap-3 px-3 py-2 rounded-xl text-xs transition-colors group">
                        <svg class="w-4 h-4 shrink-0 {{ request()->routeIs('operator.routers.*') ? 'text-blue-600' : 'text-gray-400 group-hover:text-gray-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/>
                        </svg>
                        <span x-show="!sidebarCollapsed">MikroTik routers</span>
                    </a>

                    <!-- Sites & hotspots -->
                    <a href="{{ route('operator.sites.index') }}"
                       class="{{ request()->routeIs('operator.sites.*') ? 'bg-blue-50 text-blue-600 font-semibold' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 font-medium' }} flex items-center gap-3 px-3 py-2 rounded-xl text-xs transition-colors group">
                        <svg class="w-4 h-4 shrink-0 {{ request()->routeIs('operator.sites.*') ? 'text-blue-600' : 'text-gray-400 group-hover:text-gray-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <span x-show="!sidebarCollapsed">Sites & hotspots</span>
                    </a>

                    <!-- Live sessions -->
                    <a href="{{ route('operator.sessions.index') }}" 
                       class="{{ request()->routeIs('operator.sessions.*') ? 'bg-blue-50 text-blue-600 font-semibold' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 font-medium' }} flex items-center gap-3 px-3 py-2 rounded-xl text-xs transition-colors group">
                        <svg class="w-4 h-4 shrink-0 {{ request()->routeIs('operator.sessions.*') ? 'text-blue-600' : 'text-gray-400 group-hover:text-gray-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                        <span x-show="!sidebarCollapsed">Live sessions</span>
                    </a>
                </div>
            </div>

            <!-- BILLING -->
            <div>
                <p x-show="!sidebarCollapsed" class="text-[10px] font-bold text-gray-400 tracking-wider uppercase px-3 mb-1.5">Billing</p>
                <div class="space-y-0.5">
                    <!-- Internet packages -->
                    <a href="{{ route('operator.packages.index') }}" 
                       class="{{ request()->routeIs('operator.packages.*') ? 'bg-blue-50 text-blue-600 font-semibold' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 font-medium' }} flex items-center gap-3 px-3 py-2 rounded-xl text-xs transition-colors group">
                        <svg class="w-4 h-4 shrink-0 {{ request()->routeIs('operator.packages.*') ? 'text-blue-600' : 'text-gray-400 group-hover:text-gray-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                        </svg>
                        <span x-show="!sidebarCollapsed">Internet packages</span>
                    </a>

                    <!-- Vouchers -->
                    <a href="{{ route('operator.vouchers.index') }}" 
                       class="{{ request()->routeIs('operator.vouchers.*') ? 'bg-blue-50 text-blue-600 font-semibold' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 font-medium' }} flex items-center gap-3 px-3 py-2 rounded-xl text-xs transition-colors group">
                        <svg class="w-4 h-4 shrink-0 {{ request()->routeIs('operator.vouchers.*') ? 'text-blue-600' : 'text-gray-400 group-hover:text-gray-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"/>
                        </svg>
                        <span x-show="!sidebarCollapsed">Vouchers</span>
                    </a>

                    <!-- Mobile Money -->
                    <a href="#" 
                       class="text-gray-600 hover:bg-gray-50 hover:text-gray-900 font-medium flex items-center justify-between px-3 py-2 rounded-xl text-xs transition-colors group">
                        <div class="flex items-center gap-3">
                            <svg class="w-4 h-4 shrink-0 text-gray-400 group-hover:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                            </svg>
                            <span x-show="!sidebarCollapsed">Mobile Money</span>
                        </div>
                        <span x-show="!sidebarCollapsed" class="w-2 h-2 rounded-full bg-amber-500"></span>
                    </a>
                </div>
            </div>

            <!-- MANAGEMENT -->
            <div>
                <p x-show="!sidebarCollapsed" class="text-[10px] font-bold text-gray-400 tracking-wider uppercase px-3 mb-1.5">Management</p>
                <div class="space-y-0.5">
                    <!-- Usage analytics -->
                    <a href="#" 
                       class="text-gray-600 hover:bg-gray-50 hover:text-gray-900 font-medium flex items-center gap-3 px-3 py-2 rounded-xl text-xs transition-colors group">
                        <svg class="w-4 h-4 shrink-0 text-gray-400 group-hover:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                        <span x-show="!sidebarCollapsed">Usage analytics</span>
                    </a>

                    <!-- Reports -->
                    <a href="#" 
                       class="text-gray-600 hover:bg-gray-50 hover:text-gray-900 font-medium flex items-center gap-3 px-3 py-2 rounded-xl text-xs transition-colors group">
                        <svg class="w-4 h-4 shrink-0 text-gray-400 group-hover:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        <span x-show="!sidebarCollapsed">Reports</span>
                    </a>

                    <!-- Team -->
                    @if(auth()->user()->hasRole('operator_admin') || (auth()->user()->hasRole('platform_admin') && session()->has('impersonate_tenant')))
                    <a href="{{ route('operator.team.index') }}"
                       class="{{ request()->routeIs('operator.team.*', 'operator.staff.*') ? 'bg-blue-50 text-blue-600 font-semibold' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 font-medium' }} flex items-center gap-3 px-3 py-2 rounded-xl text-xs transition-colors group">
                        <svg class="w-4 h-4 shrink-0 {{ request()->routeIs('operator.team.*', 'operator.staff.*') ? 'text-blue-600' : 'text-gray-400 group-hover:text-gray-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                        </svg>
                        <span x-show="!sidebarCollapsed">System users & roles</span>
                    </a>
                    @endif

                    <!-- Settings -->
                    <a href="{{ route('operator.settings.index') }}"
                       class="{{ request()->routeIs('operator.settings.*') ? 'bg-blue-50 text-blue-600 font-semibold' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 font-medium' }} flex items-center gap-3 px-3 py-2 rounded-xl text-xs transition-colors group">
                        <svg class="w-4 h-4 shrink-0 {{ request()->routeIs('operator.settings.*') ? 'text-blue-600' : 'text-gray-400 group-hover:text-gray-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <span x-show="!sidebarCollapsed">Settings</span>
                    </a>
                </div>
            </div>
            @endif

        </nav>
    </div>

    <!-- Bottom Sidebar Section -->
    <div class="border-t border-gray-100 p-3 bg-white">

        <!-- User Profile Card -->
        <div class="relative" x-data="{ userMenuOpen: false }">
            <div @click="userMenuOpen = !userMenuOpen" 
                 class="flex items-center justify-between p-2 rounded-xl hover:bg-gray-50 cursor-pointer transition-colors group">
                <div class="flex items-center gap-2.5 min-w-0">
                    <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-700 font-bold text-xs flex items-center justify-center shrink-0">
                        {{ $userInitials }}
                    </div>
                    <div class="min-w-0" x-show="!sidebarCollapsed">
                        <p class="text-xs font-bold text-gray-900 truncate">{{ $user?->name ?? 'Sarah Nakato' }}</p>
                        <p class="text-[10px] text-gray-400 truncate">{{ $user?->getRoleNames()->first() ?? 'Business owner' }}</p>
                    </div>
                </div>
                <svg x-show="!sidebarCollapsed" class="w-4 h-4 text-gray-400 group-hover:text-gray-600 shrink-0 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </div>

            <!-- Profile Dropdown Menu -->
            <div x-show="userMenuOpen" 
                 @click.outside="userMenuOpen = false"
                 x-cloak
                 class="absolute bottom-14 left-0 w-52 bg-white rounded-xl shadow-xl border border-gray-100 py-1.5 z-50">
                <div class="px-4 py-2 border-b border-gray-100">
                    <p class="text-xs font-bold text-gray-900">{{ $user?->name }}</p>
                    <p class="text-[11px] text-gray-400 truncate">{{ $user?->email }}</p>
                </div>
                <a href="{{ route('profile.edit') }}" class="block px-4 py-2 text-xs font-medium text-gray-700 hover:bg-gray-50">Profile Settings</a>
                @if($user?->hasRole('platform_admin') && session()->has('impersonate_tenant'))
                    <a href="{{ route('platform.stop-impersonate') }}" class="block px-4 py-2 text-xs font-semibold text-amber-700 hover:bg-amber-50">Exit Impersonation</a>
                @endif
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="w-full text-left px-4 py-2 text-xs font-medium text-red-600 hover:bg-red-50">
                        Log Out
                    </button>
                </form>
            </div>
        </div>

    </div>

</aside>
