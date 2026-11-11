<x-app-layout>
    <div class="py-8 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto space-y-6"
         x-data="{ 
             selectAll: false, 
             selected: [], 
             setupGuideOpen: false, 
             pingAllLoading: false 
         }">

        <!-- Status Message Banner if Present -->
        @if(session('status'))
            <div class="rounded-xl bg-emerald-50 border border-emerald-200 p-4 flex items-center justify-between text-emerald-800 text-sm">
                <div class="flex items-center gap-3">
                    <svg class="w-5 h-5 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    <span>{{ session('status') }}</span>
                </div>
                <button type="button" @click="$el.parentElement.remove()" class="text-emerald-500 hover:text-emerald-700">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        @endif

        @if($errors->any())
            <div class="rounded-xl bg-red-50 border border-red-200 p-4 flex items-center justify-between text-red-800 text-sm">
                <div class="flex items-center gap-3">
                    <svg class="w-5 h-5 text-red-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>{{ $errors->first() }}</span>
                </div>
                <button type="button" @click="$el.parentElement.remove()" class="text-red-500 hover:text-red-700">&times;</button>
            </div>
        @endif

        <!-- Header with Multi-Tenant Awareness & Action Buttons -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-2xl font-bold tracking-tight text-gray-900">MikroTik routers</h1>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-blue-50 text-blue-700 border border-blue-200">
                        {{ $tenant->name ?? 'Workspace' }}
                    </span>
                </div>
                <p class="text-gray-500 text-xs sm:text-sm mt-1">Monitor connectivity, resource health, and execute remote management actions across your dedicated network nodes.</p>
            </div>

            <!-- Page-Level Action Buttons -->
            <div class="flex flex-wrap items-center gap-2 sm:gap-2.5">
                <!-- Ping All Routers Action Button -->
                <form method="POST" action="{{ route('operator.routers.ping-all') }}" @submit="pingAllLoading = true">
                    @csrf
                    <button type="submit" 
                            :disabled="pingAllLoading"
                            class="inline-flex items-center gap-2 px-3.5 py-2 bg-white border border-gray-200 hover:border-emerald-300 hover:bg-emerald-50/50 rounded-xl text-xs sm:text-sm font-semibold text-gray-700 hover:text-emerald-700 shadow-xs transition-colors disabled:opacity-50">
                        <svg x-show="!pingAllLoading" class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                        <svg x-show="pingAllLoading" class="animate-spin w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" x-cloak>
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span x-text="pingAllLoading ? 'Checking...' : 'Ping all status'">Ping all status</span>
                    </button>
                </form>

                <!-- Setup Guide Action Button -->
                <button type="button" 
                        @click="setupGuideOpen = true"
                        class="inline-flex items-center gap-2 px-3.5 py-2 bg-white border border-gray-200 rounded-xl text-xs sm:text-sm font-semibold text-gray-700 shadow-xs hover:bg-gray-50 hover:border-gray-300 transition-colors">
                    <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                    </svg>
                    <span>Setup guide</span>
                </button>

                <!-- Router Settings Action Button -->
                <a href="{{ route('operator.settings.router') }}" 
                   class="inline-flex items-center gap-2 px-3.5 py-2 bg-white border border-gray-200 hover:border-blue-300 hover:bg-blue-50/50 rounded-xl text-xs sm:text-sm font-semibold text-gray-700 hover:text-blue-700 shadow-xs transition-colors">
                    <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    <span>Router settings</span>
                </a>

                <!-- Connect Router Action Button -->
                <a href="{{ route('operator.routers.create') }}" 
                   class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded-xl text-xs sm:text-sm font-semibold text-white shadow-xs transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    <span>Connect router</span>
                </a>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            
            <!-- Connected routers -->
            <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-xs flex flex-col justify-between">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-xs font-semibold text-gray-500">Connected routers</p>
                        <p class="text-3xl font-extrabold text-gray-900 mt-2">{{ $totalRouters }}</p>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-gray-50 border border-gray-100 flex items-center justify-center text-gray-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/>
                        </svg>
                    </div>
                </div>
                <p class="text-[11px] font-medium text-gray-400 mt-3">Across {{ count($sites) }} operating site(s)</p>
            </div>

            <!-- Online now -->
            <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-xs flex flex-col justify-between">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-xs font-semibold text-gray-500">Online now</p>
                        <p class="text-3xl font-extrabold text-gray-900 mt-2">{{ $onlineRouters }}</p>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 border border-emerald-100 flex items-center justify-center text-emerald-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/>
                        </svg>
                    </div>
                </div>
                <p class="text-[11px] font-medium text-emerald-600 mt-3">{{ $onlineRouters > 0 ? 'Live polling active' : 'No routers online' }}</p>
            </div>

            <!-- Offline -->
            <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-xs flex flex-col justify-between">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-xs font-semibold text-gray-500">Offline</p>
                        <p class="text-3xl font-extrabold text-red-600 mt-2">{{ $offlineRouters }}</p>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-red-50 border border-red-100 flex items-center justify-center text-red-500">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636a9 9 0 010 12.728m0 0l-2.829-2.829m2.829 2.829L21 21M15.536 8.464a5 5 0 010 7.072m0 0l-2.829-2.829M3 3l18 18"/>
                        </svg>
                    </div>
                </div>
                <p class="text-[11px] font-medium text-gray-400 mt-3">
                    @if($unreachableRouter)
                        <span class="text-red-600 font-semibold">{{ $unreachableRouter->name }}</span> · {{ $unreachableRouter->last_seen_at ? $unreachableRouter->last_seen_at->diffForHumans(null, true) : 'Offline' }}
                    @elseif($totalRouters === 0)
                        No routers added
                    @else
                        All nodes operational
                    @endif
                </p>
            </div>

            <!-- Average CPU load -->
            <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-xs flex flex-col justify-between">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-xs font-semibold text-gray-500">Average CPU load</p>
                        <p class="text-3xl font-extrabold text-gray-900 mt-2">{{ $onlineRouters > 0 ? number_format($avgCpu) . '%' : '—' }}</p>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-blue-50 border border-blue-100 flex items-center justify-center text-blue-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 3v2m6-2v2M9 19v2m6-2v2M3 9h2m-2 6h2m16-6h2m-2 6h2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/>
                        </svg>
                    </div>
                </div>
                <p class="text-[11px] font-medium text-gray-400 mt-3">{{ $onlineRouters > 0 ? 'Isolated tenant resources' : 'No active nodes' }}</p>
            </div>

        </div>

        <!-- Unreachable Alert Banner (If Any Router is Offline) -->
        @if($unreachableRouter)
            <div class="bg-amber-50/90 border border-amber-200/80 rounded-2xl p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="flex items-start gap-3">
                    <div class="w-6 h-6 rounded-full bg-amber-100 border border-amber-300 flex items-center justify-center text-amber-700 shrink-0 mt-0.5">
                        <span class="text-xs font-bold">!</span>
                    </div>
                    <div>
                        <p class="text-xs font-bold text-gray-900">{{ $unreachableRouter->name }} is unreachable</p>
                        <p class="text-xs text-gray-600 mt-0.5">
                            Last seen {{ $unreachableRouter->last_seen_at ? $unreachableRouter->last_seen_at->format('H:i T') : 'Recently' }} · API and ICMP checks failed. Check power, uplink and VPN tunnel.
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2 self-end sm:self-center">
                    <form method="POST" action="{{ route('operator.routers.ping', $unreachableRouter) }}">
                        @csrf
                        <button type="submit" class="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white rounded-lg text-xs font-semibold shadow-xs">
                            Retry Ping
                        </button>
                    </form>
                    <a href="{{ route('operator.routers.show', $unreachableRouter) }}" 
                       class="text-xs font-bold text-amber-900 hover:text-amber-950 inline-flex items-center gap-1">
                        <span>Diagnose</span>
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </a>
                </div>
            </div>
        @endif

        <!-- Floating Bulk Action Bar (When 1+ Routers Selected) -->
        <div x-show="selected.length > 0" 
             x-cloak
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 -translate-y-2"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 -translate-y-2"
             class="bg-slate-900 text-white rounded-2xl p-4 shadow-xl border border-slate-800 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            
            <div class="flex items-center gap-3">
                <span class="w-7 h-7 rounded-lg bg-blue-600 text-white font-bold text-xs flex items-center justify-center" x-text="selected.length"></span>
                <span class="text-xs font-semibold text-slate-200">
                    <span x-text="selected.length"></span> router(s) selected for workspace <strong>{{ $tenant->name ?? 'Acme' }}</strong>
                </span>
            </div>

            <!-- Bulk Action Trigger Buttons -->
            <form method="POST" action="{{ route('operator.routers.bulk-action') }}" class="flex flex-wrap items-center gap-2" id="bulkActionForm">
                @csrf
                <input type="hidden" name="action" id="bulkActionInput" value="">
                
                <template x-for="id in selected" :key="id">
                    <input type="hidden" name="router_ids[]" :value="id">
                </template>

                <button type="button" 
                        @click="document.getElementById('bulkActionInput').value = 'ping'; document.getElementById('bulkActionForm').submit();"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-emerald-400 border border-slate-700 rounded-xl text-xs font-semibold transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    <span>Ping Selected</span>
                </button>

                <button type="button" 
                        @click="document.getElementById('bulkActionInput').value = 'sync'; document.getElementById('bulkActionForm').submit();"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-blue-400 border border-slate-700 rounded-xl text-xs font-semibold transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    <span>Sync Selected</span>
                </button>

                <button type="button" 
                        @click="window.confirmAction('Reboot all selected routers?').then(confirmed => { if (confirmed) { document.getElementById('bulkActionInput').value = 'reboot'; document.getElementById('bulkActionForm').requestSubmit(); } })"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-amber-400 border border-slate-700 rounded-xl text-xs font-semibold transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    <span>Reboot Selected</span>
                </button>

                <button type="button" 
                        @click="window.confirmAction('Are you sure you want to delete all selected routers? This cannot be undone.').then(confirmed => { if (confirmed) { document.getElementById('bulkActionInput').value = 'delete'; document.getElementById('bulkActionForm').requestSubmit(); } })"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-red-950/80 hover:bg-red-900 text-red-300 border border-red-800 rounded-xl text-xs font-semibold transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    <span>Delete Selected</span>
                </button>

                <button type="button" 
                        @click="selected = []; selectAll = false;"
                        class="text-xs text-slate-400 hover:text-white px-2 py-1">
                    Cancel
                </button>
            </form>
        </div>

        <!-- Filter & Search Toolbar -->
        <form method="GET" action="{{ route('operator.routers.index') }}" class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
            
            <div class="flex flex-wrap items-center gap-2.5 flex-1">
                <!-- Search Input -->
                <div class="relative w-full sm:w-72">
                    <svg class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    <input type="text" 
                           name="search" 
                           value="{{ request('search') }}"
                           placeholder="Search router, IP or serial number" 
                           class="w-full pl-9 pr-4 py-2 bg-white border border-gray-200 rounded-xl text-xs placeholder-gray-400 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 shadow-xs">
                </div>

                <!-- Site Filter -->
                <select name="site_id"
                        onchange="this.form.submit()"
                        class="bg-white border border-gray-200 rounded-xl px-3 py-2 text-xs text-gray-700 shadow-xs focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="all">All sites</option>
                    @foreach($siteRecords as $siteRecord)
                        <option value="{{ $siteRecord->id }}" @selected(request('site_id') == $siteRecord->id)>{{ $siteRecord->name }}</option>
                    @endforeach
                </select>

                <!-- Status Filter -->
                <select name="status" 
                        onchange="this.form.submit()"
                        class="bg-white border border-gray-200 rounded-xl px-3 py-2 text-xs text-gray-700 shadow-xs focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="all">All statuses</option>
                    <option value="online" {{ request('status') == 'online' ? 'selected' : '' }}>Online</option>
                    <option value="offline" {{ request('status') == 'offline' ? 'selected' : '' }}>Offline</option>
                    <option value="provisioning" {{ request('status') == 'provisioning' ? 'selected' : '' }}>Provisioning</option>
                </select>
            </div>

            <!-- Clear Search / Filters if active -->
            @if(request('search') || (request('site_id') && request('site_id') !== 'all') || (request('site') && request('site') !== 'all') || (request('status') && request('status') !== 'all'))
                <a href="{{ route('operator.routers.index') }}" class="text-xs text-blue-600 hover:text-blue-800 font-semibold self-center">
                    Reset filters
                </a>
            @endif

        </form>

        <!-- Table Container -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-xs overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50/80">
                        <tr>
                            <th scope="col" class="w-10 px-4 py-3 text-left">
                                <input type="checkbox" 
                                       @click="selectAll = !selectAll; selected = selectAll ? [{{ $routers->pluck('id')->join(',') }}] : []"
                                       :checked="selectAll && selected.length > 0"
                                       class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-3.5 h-3.5">
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider">
                                <div class="flex items-center gap-1 cursor-pointer hover:text-gray-700">
                                    <span>Router / model</span>
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                </div>
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider">Management IP</th>
                            <th scope="col" class="px-4 py-3 text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider">Site</th>
                            <th scope="col" class="px-4 py-3 text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider">CPU / memory</th>
                            <th scope="col" class="px-4 py-3 text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider">Last seen</th>
                            <th scope="col" class="px-4 py-3 text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider">Status</th>
                            <th scope="col" class="px-4 py-3 text-right text-[11px] font-bold text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white text-xs">
                        @forelse($routers as $router)
                            <tr class="hover:bg-gray-50/70 transition-colors">
                                <td class="px-4 py-3.5 whitespace-nowrap">
                                    <input type="checkbox" 
                                           :value="{{ $router->id }}" 
                                           x-model="selected"
                                           class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-3.5 h-3.5">
                                </td>

                                <!-- Router / model -->
                                <td class="px-4 py-3.5 whitespace-nowrap">
                                    <a href="{{ route('operator.routers.show', $router) }}" class="font-bold text-gray-900 hover:text-blue-600 block">
                                        {{ $router->name }}
                                    </a>
                                    <span class="text-[11px] text-gray-400 block mt-0.5">
                                        {{ $router->hardware_model ?? '—' }}
                                    </span>
                                </td>

                                <!-- Management IP -->
                                <td class="px-4 py-3.5 whitespace-nowrap font-mono text-gray-600">
                                    {{ $router->management_ip ?? '—' }}
                                </td>

                                <!-- Site -->
                                <td class="px-4 py-3.5 whitespace-nowrap text-gray-700">
                                    @if($router->site_id && $router->assignedSite)
                                        <span class="bg-blue-50 text-blue-700 px-2 py-1 rounded text-xs font-medium border border-blue-100">
                                            {{ $router->assignedSite->name }}
                                        </span>
                                    @else
                                        <span class="text-gray-400 italic text-xs">Unassigned</span>
                                    @endif
                                </td>

                                <!-- CPU / Memory -->
                                <td class="px-4 py-3.5 whitespace-nowrap text-gray-600">
                                    @if($router->status === 'online')
                                        {{ $router->cpu_usage }}% / {{ $router->memory_usage }}%
                                    @else
                                        <span class="text-gray-400">— / —</span>
                                    @endif
                                </td>

                                <!-- Last Seen -->
                                <td class="px-4 py-3.5 whitespace-nowrap text-gray-500">
                                    @if($router->status === 'online')
                                        Just now
                                    @elseif($router->last_seen_at)
                                        {{ $router->last_seen_at->format('H:i T') }}
                                    @else
                                        Never
                                    @endif
                                </td>

                                <!-- Status Badge -->
                                <td class="px-4 py-3.5 whitespace-nowrap">
                                    @if($router->status === 'online')
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/60">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                            Online
                                        </span>
                                    @elseif($router->status === 'provisioning')
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-blue-50 text-blue-700 border border-blue-200/60">
                                            <span class="w-1.5 h-1.5 rounded-full bg-blue-500 animate-pulse"></span>
                                            Provisioning
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-red-50 text-red-700 border border-red-200/60">
                                            <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span>
                                            Offline
                                        </span>
                                    @endif
                                </td>

                                <!-- Action Buttons (Inline Quick Actions + Dropdown) -->
                                <td class="px-4 py-3.5 whitespace-nowrap text-right" x-data="{
                                    open: false,
                                    pinging: false,
                                    menuPosition: {},
                                    toggleMenu(event) {
                                        this.open = !this.open;
                                        if (!this.open) return;
                                        const rect = event.currentTarget.getBoundingClientRect();
                                        const menuHeight = 340;
                                        const top = rect.bottom + menuHeight > window.innerHeight
                                            ? Math.max(8, rect.top - menuHeight - 6)
                                            : rect.bottom + 6;
                                        this.menuPosition = { top: `${top}px`, right: `${Math.max(8, window.innerWidth - rect.right)}px` };
                                    }
                                }"
                                    @scroll.window="open = false">
                                    <div class="inline-flex items-center justify-end gap-1">
                                        
                                        <!-- Quick Action: Ping / Health Check -->
                                        <form method="POST" action="{{ route('operator.routers.ping', $router) }}" @submit="pinging = true" class="inline">
                                            @csrf
                                            <button type="submit" 
                                                    :disabled="pinging"
                                                    title="Ping router / check connection health" 
                                                    class="w-7 h-7 rounded-lg inline-flex items-center justify-center text-gray-400 hover:text-emerald-600 hover:bg-emerald-50 transition-colors">
                                                <svg x-show="!pinging" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                                </svg>
                                                <svg x-show="pinging" class="animate-spin w-3.5 h-3.5 text-emerald-600" fill="none" viewBox="0 0 24 24" x-cloak>
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                </svg>
                                            </button>
                                        </form>

                                        <!-- Quick Action: Sync Config & RADIUS -->
                                        <form method="POST" action="{{ route('operator.routers.sync', $router) }}" class="inline">
                                            @csrf
                                            <button type="submit" 
                                                    title="Sync RADIUS profiles & config" 
                                                    class="w-7 h-7 rounded-lg inline-flex items-center justify-center text-gray-400 hover:text-blue-600 hover:bg-blue-50 transition-colors">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                                </svg>
                                            </button>
                                        </form>

                                        <!-- Quick Action: Reboot Router -->
                                        <form method="POST" action="{{ route('operator.routers.reboot', $router) }}" data-confirm-action="Reboot router {{ $router->name }}? Device will temporarily go offline." class="inline">
                                            @csrf
                                            <button type="submit" 
                                                    title="Reboot router" 
                                                    class="w-7 h-7 rounded-lg inline-flex items-center justify-center text-gray-400 hover:text-amber-600 hover:bg-amber-50 transition-colors">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                </svg>
                                            </button>
                                        </form>

                                        <!-- Quick Action: View / Manage -->
                                        <a href="{{ route('operator.routers.show', $router) }}" 
                                           title="View details & telemetries" 
                                           class="w-7 h-7 rounded-lg inline-flex items-center justify-center text-gray-400 hover:text-gray-900 hover:bg-gray-100 transition-colors">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                            </svg>
                                        </a>

                                        <!-- Action Dropdown for Secondary Tasks -->
                                        <div class="relative inline-block text-left">
                                            <button @click="toggleMenu($event)"
                                                    type="button" 
                                                    title="More actions"
                                                    aria-label="Router actions"
                                                    :aria-expanded="open"
                                                    class="w-7 h-7 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h.01M12 12h.01M19 12h.01"/>
                                                </svg>
                                            </button>

                                            <div x-show="open"
                                                 @click.outside="open = false" 
                                                 x-cloak
                                                 :style="menuPosition"
                                                 class="fixed w-52 bg-white rounded-xl shadow-xl border border-gray-200 py-1.5 z-[100] text-left">
                                                
                                                <a href="{{ route('operator.routers.show', $router) }}" 
                                                   class="flex items-center gap-2.5 px-4 py-2 text-xs text-gray-700 hover:bg-gray-50">
                                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                                    <span>View details & telemetry</span>
                                                </a>

                                                <a href="{{ route('operator.routers.edit', ['tenant' => $tenant->slug, 'router' => $router->id]) }}"
                                                   class="flex items-center gap-2.5 px-4 py-2 text-xs text-gray-700 hover:bg-gray-50">
                                                    <span>Edit router details</span>
                                                </a>

                                                <form method="POST" action="{{ route('operator.routers.ping', $router) }}">
                                                    @csrf
                                                    <button type="submit" class="w-full flex items-center gap-2.5 px-4 py-2 text-xs text-gray-700 hover:bg-gray-50 text-left">
                                                        <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                                        <span>Ping / Check health</span>
                                                    </button>
                                                </form>

                                                <form method="POST" action="{{ route('operator.routers.sync', $router) }}">
                                                    @csrf
                                                    <button type="submit" class="w-full flex items-center gap-2.5 px-4 py-2 text-xs text-gray-700 hover:bg-gray-50 text-left">
                                                        <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                                        <span>Sync RADIUS config</span>
                                                    </button>
                                                </form>

                                                <button type="button"
                                                        x-data="{ testing: false, result: null }"
                                                        @click="
                                                            testing = true;
                                                            result = null;
                                                            window.apiFetch('{{ route('operator.routers.test', $router) }}', { method: 'POST' })
                                                            .then(data => {
                                                                result = data;
                                                                alert((data.ok ? '✅ ' : '❌ ') + data.message);
                                                            })
                                                            .catch(error => {
                                                                result = { ok: false, message: error.message || 'Network Error' };
                                                                alert(error.message || 'Network Error');
                                                            })
                                                            .finally(() => testing = false);
                                                        "
                                                        :disabled="testing"
                                                        class="w-full flex items-center gap-2.5 px-4 py-2 text-xs text-blue-600 hover:bg-blue-50 text-left disabled:opacity-50">
                                                    <span x-show="!testing && !result">Test LAN API</span>
                                                    <span x-show="testing">Testing...</span>
                                                    <span x-show="result" x-text="result && result.message"
                                                          :class="result && result.ok ? 'text-emerald-700' : 'text-red-600'"></span>
                                                </button>

                                                <button type="button" 
                                                        @click="navigator.clipboard.writeText('/tool fetch mode=https url=\'https://{{ $tenant->slug }}.sopraent.com/provision/demo\''); open = false; alert('Provisioning script copied to clipboard!');" 
                                                        class="w-full flex items-center gap-2.5 px-4 py-2 text-xs text-gray-700 hover:bg-gray-50 text-left">
                                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>
                                                    <span>Copy provision script</span>
                                                </button>

                                                <form method="POST" action="{{ route('operator.routers.reboot', $router) }}" data-confirm-action="Reboot router {{ $router->name }}?">
                                                    @csrf
                                                    <button type="submit" class="w-full flex items-center gap-2.5 px-4 py-2 text-xs text-amber-700 hover:bg-amber-50 text-left">
                                                        <svg class="w-4 h-4 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                                        <span>Reboot router</span>
                                                    </button>
                                                </form>

                                                <div class="h-px bg-gray-100 my-1"></div>

                                                <form method="POST" action="{{ route('operator.routers.destroy', $router) }}" data-confirm-action="Are you sure you want to remove router {{ $router->name }}?">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="w-full flex items-center gap-2.5 px-4 py-2 text-xs text-red-600 hover:bg-red-50 text-left">
                                                        <svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                        <span>Delete router</span>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>

                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-6 py-12 text-center text-gray-400 text-xs">
                                    No routers found in workspace <strong>{{ $tenant->name ?? 'Acme' }}</strong>. Click "Connect router" to add your first MikroTik device.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Table Pagination Footer -->
            <div class="px-4 py-3 bg-white border-t border-gray-100 flex items-center justify-between">
                <p class="text-xs text-gray-500">
                    Showing <span class="font-bold text-gray-700">{{ $routers->firstItem() ?? 0 }}-{{ $routers->lastItem() ?? 0 }}</span> of <span class="font-bold text-gray-700">{{ $routers->total() }}</span> records
                </p>
                <div>
                    {{ $routers->links() }}
                </div>
            </div>

        </div>

        <!-- Footer Overview Widgets (Configuration & Backup + RADIUS Service) -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2">
            
            <!-- Configuration & backup -->
            <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-xs flex flex-col justify-between">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-bold text-gray-900">Configuration & backup</h3>
                    <form method="POST" action="{{ route('operator.routers.ping-all') }}">
                        @csrf
                        <button type="submit" class="text-xs font-semibold text-blue-600 hover:text-blue-700">Check All Backups</button>
                    </form>
                </div>
                <p class="text-xs text-gray-500 mb-4">
                    {{ $onlineRouters }} of {{ $totalRouters }} routers backed up today. Offline router retries when connectivity is restored.
                </p>
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-blue-50 text-blue-700 border border-blue-200/60">
                        <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span>
                        Scheduled
                    </span>
                    <span class="text-[11px] text-gray-400">Isolated workspace: {{ $tenant->slug ?? 'acme' }}</span>
                </div>
            </div>

            <!-- RADIUS service -->
            <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-xs flex flex-col justify-between">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-bold text-gray-900">RADIUS service</h3>
                    <a href="{{ route('operator.settings.router') }}" class="text-xs font-semibold text-blue-600 hover:text-blue-700">Integration settings</a>
                </div>
                <p class="text-xs text-gray-500 mb-4">
                    12 ms auth latency · 99.98% availability · Auto-sync enabled
                </p>
                <div class="flex items-center justify-between">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/60">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                        Healthy
                    </span>
                    <form method="POST" action="{{ route('operator.settings.router.sync-all') }}">
                        @csrf
                        <button type="submit" class="text-xs font-semibold text-blue-600 hover:text-blue-800">
                            Sync All Routers &rarr;
                        </button>
                    </form>
                </div>
            </div>

        </div>

        <!-- Setup Guide Modal (Interactive Walkthrough) -->
        <div x-show="setupGuideOpen" 
             x-cloak 
             class="fixed inset-0 z-50 overflow-y-auto"
             aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                <div x-show="setupGuideOpen" 
                     x-transition:enter="ease-out duration-300"
                     x-transition:enter-start="opacity-0"
                     x-transition:enter-end="opacity-100"
                     x-transition:leave="ease-in duration-200"
                     x-transition:leave-start="opacity-100"
                     x-transition:leave-end="opacity-0"
                     @click="setupGuideOpen = false" 
                     class="fixed inset-0 transition-opacity bg-gray-900/60 backdrop-blur-xs"></div>

                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

                <div x-show="setupGuideOpen" 
                     x-transition:enter="ease-out duration-300"
                     x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                     x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                     x-transition:leave="ease-in duration-200"
                     x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                     x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                     class="inline-block w-full max-w-2xl p-6 my-8 overflow-hidden text-left align-middle transition-all transform bg-white shadow-2xl rounded-3xl border border-gray-100">
                    
                    <div class="flex items-center justify-between pb-4 border-b border-gray-100">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center font-bold">
                                📡
                            </div>
                            <div>
                                <h3 class="text-base font-bold text-gray-900" id="modal-title">MikroTik Router Provisioning Guide</h3>
                                <p class="text-xs text-gray-500">Dedicated multi-tenant onboarding for workspace: <strong>{{ $tenant->name ?? 'Acme' }}</strong></p>
                            </div>
                        </div>
                        <button type="button" @click="setupGuideOpen = false" class="text-gray-400 hover:text-gray-600 p-1 rounded-lg">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <div class="mt-4 space-y-4 text-xs">
                        <div class="p-3 rounded-xl bg-blue-50/70 border border-blue-200/60 text-blue-800">
                            <strong>Multi-Tenant Isolation Notice:</strong> Each router connected under your workspace is strictly isolated. Captive portal sessions and RADIUS accounting records are tagged with your workspace ID (<strong>#{{ $tenant->id ?? 1 }}</strong>).
                        </div>

                        <div>
                            <h4 class="font-bold text-gray-900 mb-1">Step 1: Open Terminal or SSH</h4>
                            <p class="text-gray-500 mb-2">Connect to your MikroTik device using WinBox (Terminal icon) or standard SSH.</p>
                        </div>

                        <div>
                            <h4 class="font-bold text-gray-900 mb-1">Step 2: Run Zero-Touch Fetch Command</h4>
                            <div class="bg-slate-900 text-slate-100 p-3 rounded-xl font-mono text-[11px] overflow-x-auto relative group">
                                <code>/tool fetch mode=https url="https://{{ $tenant->slug ?? 'acme' }}.sopraent.com/provision/{{ base64_encode($tenant->slug ?? 'acme') }}" dst-path=sopraent_init.rsc; /import sopraent_init.rsc</code>
                            </div>
                        </div>

                        <div>
                            <h4 class="font-bold text-gray-900 mb-1">Step 3: Verify Automated Connection</h4>
                            <p class="text-gray-500">Once imported, the router will automatically register, create the hotspot interface, and report its telemetry here.</p>
                        </div>
                    </div>

                    <div class="mt-6 pt-4 border-t border-gray-100 flex items-center justify-between">
                        <a href="{{ route('operator.settings.router') }}" class="text-xs font-semibold text-blue-600 hover:text-blue-800">
                            Configure Router API Settings &rarr;
                        </a>
                        <button type="button" @click="setupGuideOpen = false" class="px-4 py-2 bg-gray-900 hover:bg-gray-800 text-white rounded-xl text-xs font-semibold">
                            Got It
                        </button>
                    </div>
                </div>
            </div>
        </div>

    </div>
</x-app-layout>
