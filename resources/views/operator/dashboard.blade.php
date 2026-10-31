<x-app-layout>
    <div class="p-6 sm:p-8 space-y-6">

        @php
            $tenantTitle = $tenant->name ?? 'KampalaLink Internet';
            $userName = auth()->user()->name ?? 'Sarah';
            $firstName = explode(' ', $userName)[0];
        @endphp

        <!-- 1. Page Header Area -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <p class="text-[11px] font-bold text-blue-600 tracking-wider uppercase mb-1">
                    {{ now()->format('l, d F Y') }}
                </p>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-gray-900 tracking-tight">
                    A clear view of your network.
                </h1>
                <p class="text-xs sm:text-sm text-gray-500 mt-1">
                    Good morning, {{ $firstName }}, Here's how {{ $tenantTitle }} is performing today.
                </p>
            </div>
            <div class="flex items-center gap-2.5 shrink-0">
                <button type="button" 
                        class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-semibold bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 shadow-xs transition-colors">
                    <svg class="w-3.5 h-3.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    Export overview
                </button>
                <a href="{{ route('operator.packages.index') }}" 
                   class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-semibold bg-blue-600 hover:bg-blue-700 text-white shadow-xs transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                    </svg>
                    Sell a package
                </a>
            </div>
        </div>

        <!-- 2. Attention Alert Banner -->
        <div class="rounded-2xl bg-amber-50/70 border border-amber-200/80 p-4 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 text-xs shadow-xs">
            <div class="flex items-center gap-3">
                <div class="w-7 h-7 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center font-bold text-xs shrink-0">
                    !
                </div>
                <div>
                    <span class="font-bold text-gray-900">1 router needs your attention</span>
                    <span class="text-gray-500 ml-1">Jinja-MKT-02 has been offline for 18 minutes. 24 customers may be affected.</span>
                </div>
            </div>
            <a href="#" class="text-amber-800 font-semibold hover:underline flex items-center gap-1 shrink-0 ml-10 sm:ml-0">
                View router &rarr;
            </a>
        </div>

        <!-- 3. Four Top KPI Cards Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            
            <!-- Card 1: Collections this month -->
            <div class="bg-white rounded-2xl border border-gray-200/80 p-5 shadow-xs hover:shadow-md transition-shadow">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-xs font-semibold text-gray-500">Collections this month</p>
                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 14l6-6m0 0l-6-6m6 6H3"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 10h18M3 14h18m-9-4v8m-7 4h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </div>
                <p class="text-2xl sm:text-3xl font-extrabold text-gray-900 tracking-tight">UGX 18.45m</p>
                <div class="flex items-center gap-1 text-xs text-emerald-600 font-semibold mt-2">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 10l7-7m0 0l7 7m-7-7v18"/>
                    </svg>
                    <span>12.8% vs September</span>
                </div>
            </div>

            <!-- Card 2: Total customers -->
            <div class="bg-white rounded-2xl border border-gray-200/80 p-5 shadow-xs hover:shadow-md transition-shadow">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-xs font-semibold text-gray-500">Total customers</p>
                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                </div>
                <p class="text-2xl sm:text-3xl font-extrabold text-gray-900 tracking-tight">
                    {{ $customerCount > 0 ? number_format($customerCount) : '1,248' }}
                </p>
                <p class="text-xs text-emerald-600 font-semibold mt-2">+46 customers this month</p>
            </div>

            <!-- Card 3: Active sessions -->
            <div class="bg-white rounded-2xl border border-gray-200/80 p-5 shadow-xs hover:shadow-md transition-shadow">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-xs font-semibold text-gray-500">Active sessions</p>
                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/>
                    </svg>
                </div>
                <p class="text-2xl sm:text-3xl font-extrabold text-gray-900 tracking-tight">
                    {{ $activeSessions > 0 ? number_format($activeSessions) : '328' }}
                </p>
                <p class="text-xs text-gray-400 font-medium mt-2">Across 6 hotspot sites</p>
            </div>

            <!-- Card 4: Routers online -->
            <div class="bg-white rounded-2xl border border-gray-200/80 p-5 shadow-xs hover:shadow-md transition-shadow">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-xs font-semibold text-gray-500">Routers online</p>
                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/>
                    </svg>
                </div>
                <p class="text-2xl sm:text-3xl font-extrabold text-gray-900 tracking-tight">11 / 12</p>
                <p class="text-xs text-gray-400 font-medium mt-2">1 offline · 99.2% uptime</p>
            </div>

        </div>

        <!-- 4. Middle 2-Column Split: Collections Chart + Network at a Glance -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            
            <!-- Left: Collections Overview Card (8 cols) -->
            <div class="lg:col-span-8 bg-white rounded-2xl border border-gray-200/80 p-6 shadow-xs flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <div>
                            <h3 class="text-sm font-bold text-gray-900">Collections overview</h3>
                            <p class="text-xs text-gray-400 mt-0.5">Confirmed collections across all sites</p>
                        </div>
                        <button type="button" class="text-xs font-semibold text-blue-600 hover:text-blue-700">
                            This month
                        </button>
                    </div>

                    <div class="flex items-baseline gap-3 my-4">
                        <span class="text-2xl sm:text-3xl font-extrabold text-gray-900 tracking-tight">UGX 18,450,000</span>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/60">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                            + 12.8%
                        </span>
                    </div>
                </div>

                <!-- SVG Area Chart -->
                <div class="w-full mt-4">
                    <div class="relative h-44 w-full">
                        <svg class="w-full h-full overflow-visible" viewBox="0 0 600 160" preserveAspectRatio="none">
                            <defs>
                                <linearGradient id="chartGradient" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#2563eb" stop-opacity="0.25"/>
                                    <stop offset="100%" stop-color="#2563eb" stop-opacity="0.0"/>
                                </linearGradient>
                            </defs>
                            
                            <!-- Subtle Horizontal Gridlines -->
                            <line x1="0" y1="20" x2="600" y2="20" stroke="#f1f5f9" stroke-width="1" />
                            <line x1="0" y1="60" x2="600" y2="60" stroke="#f1f5f9" stroke-width="1" />
                            <line x1="0" y1="100" x2="600" y2="100" stroke="#f1f5f9" stroke-width="1" />
                            <line x1="0" y1="140" x2="600" y2="140" stroke="#f1f5f9" stroke-width="1" />

                            <!-- Fill Area -->
                            <path d="M 0 115 
                                     Q 25 110, 40 108 
                                     T 80 112 
                                     T 120 102 
                                     T 160 106 
                                     T 200 95 
                                     T 240 100 
                                     T 280 88 
                                     T 320 92 
                                     T 360 82 
                                     T 400 90 
                                     T 440 78 
                                     T 480 88 
                                     T 520 75 
                                     T 560 84 
                                     T 600 68 
                                     L 600 160 L 0 160 Z" 
                                  fill="url(#chartGradient)"/>

                            <!-- Stroke Line -->
                            <path d="M 0 115 
                                     Q 25 110, 40 108 
                                     T 80 112 
                                     T 120 102 
                                     T 160 106 
                                     T 200 95 
                                     T 240 100 
                                     T 280 88 
                                     T 320 92 
                                     T 360 82 
                                     T 400 90 
                                     T 440 78 
                                     T 480 88 
                                     T 520 75 
                                     T 560 84 
                                     T 600 68" 
                                  fill="none" stroke="#2563eb" stroke-width="2.5" stroke-linecap="round"/>
                        </svg>
                    </div>

                    <!-- Date Markers Along Bottom -->
                    <div class="flex justify-between text-[11px] text-gray-400 font-medium pt-2 border-t border-gray-100">
                        <span>01 Oct</span>
                        <span>07 Oct</span>
                        <span>14 Oct</span>
                        <span>21 Oct</span>
                        <span>31 Oct</span>
                    </div>
                </div>
            </div>

            <!-- Right: Network at a Glance (4 cols) -->
            <div class="lg:col-span-4 bg-white rounded-2xl border border-gray-200/80 p-6 shadow-xs flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h3 class="text-sm font-bold text-gray-900">Network at a glance</h3>
                            <p class="text-xs text-gray-400 mt-0.5">Live activity · refreshed 10:42 EAT</p>
                        </div>
                        <a href="#" class="text-xs font-semibold text-blue-600 hover:underline">View all</a>
                    </div>

                    <!-- Download / Upload Throughput -->
                    <div class="grid grid-cols-2 gap-4 py-3 border-y border-gray-100 mb-5">
                        <div>
                            <p class="text-xs font-medium text-gray-400 flex items-center gap-1">
                                <span class="text-blue-500">&darr;</span> Download
                            </p>
                            <p class="text-xl font-extrabold text-gray-900 mt-1">842 Mbps</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-400 flex items-center gap-1">
                                <span class="text-blue-500">&uarr;</span> Upload
                            </p>
                            <p class="text-xl font-extrabold text-gray-900 mt-1">186 Mbps</p>
                        </div>
                    </div>

                    <!-- Progress Bars -->
                    <div class="space-y-4">
                        <div>
                            <div class="flex justify-between text-xs font-medium mb-1.5">
                                <span class="text-gray-600">Backhaul utilization</span>
                                <span class="font-bold text-gray-900">64%</span>
                            </div>
                            <div class="w-full bg-gray-100 rounded-full h-2 overflow-hidden">
                                <div class="bg-blue-600 h-2 rounded-full" style="width: 64%"></div>
                            </div>
                        </div>

                        <div>
                            <div class="flex justify-between text-xs font-medium mb-1.5">
                                <span class="text-gray-600">RADIUS availability</span>
                                <span class="font-bold text-gray-900">99.98%</span>
                            </div>
                            <div class="w-full bg-gray-100 rounded-full h-2 overflow-hidden">
                                <div class="bg-emerald-500 h-2 rounded-full" style="width: 99.98%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer Status Badges -->
                <div class="flex items-center gap-2 pt-5 border-t border-gray-100 mt-6">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/60">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                        RADIUS healthy
                    </span>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/60">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                        MoMo connected
                    </span>
                </div>
            </div>

        </div>

        <!-- 5. Bottom 2-Column Split: Recent Collections + Action List -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            
            <!-- Left: Recent Collections Table (8 cols) -->
            <div class="lg:col-span-8 bg-white rounded-2xl border border-gray-200/80 shadow-xs overflow-hidden flex flex-col justify-between">
                <div>
                    <!-- Header -->
                    <div class="p-6 pb-4 flex items-center justify-between border-b border-gray-100">
                        <div>
                            <h3 class="text-sm font-bold text-gray-900">Recent collections</h3>
                            <p class="text-xs text-gray-400 mt-0.5">UGX 845,000 collected today · 32 transactions</p>
                        </div>
                        <button class="text-gray-400 hover:text-gray-600 p-1 rounded-lg">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M6 10a2 2 0 11-4 0 2 2 0 014 0zM12 10a2 2 0 11-4 0 2 2 0 014 0zM16 12a2 2 0 100-4 2 2 0 000 4z"/></svg>
                        </button>
                    </div>

                    <!-- Table -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-100 text-left text-xs">
                            <thead class="bg-gray-50/60 text-gray-400 font-semibold uppercase text-[10px] tracking-wider">
                                <tr>
                                    <th class="w-10 px-5 py-3">
                                        <input type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    </th>
                                    <th class="px-4 py-3">Customer &darr;</th>
                                    <th class="px-4 py-3">Package / amount</th>
                                    <th class="px-4 py-3">Provider</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 text-gray-700">
                                
                                <tr class="hover:bg-gray-50/60 transition-colors">
                                    <td class="px-5 py-3.5">
                                        <input type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <div class="font-bold text-gray-900">Amina Namusoke</div>
                                        <div class="text-[11px] text-gray-400">10:38 EAT</div>
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <div class="font-bold text-gray-900">UGX 60,000</div>
                                        <div class="text-[11px] text-gray-400">Home Monthly</div>
                                    </td>
                                    <td class="px-4 py-3.5 font-medium text-gray-600">
                                        MTN MoMo
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/60">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Matched
                                        </span>
                                    </td>
                                    <td class="px-4 py-3.5 text-right text-gray-400 relative" x-data="{ open: false }">
                                        <button @click="open = !open" class="hover:text-gray-600 p-1 rounded-lg hover:bg-gray-100 transition-colors focus:outline-none">···</button>
                                        <div x-show="open" 
                                             @click.away="open = false" 
                                             x-transition:enter="transition ease-out duration-100"
                                             x-transition:enter-start="transform opacity-0 scale-95"
                                             x-transition:enter-end="transform opacity-100 scale-100"
                                             x-transition:leave="transition ease-in duration-75"
                                             x-transition:leave-start="transform opacity-100 scale-100"
                                             x-transition:leave-end="transform opacity-0 scale-95"
                                             class="absolute right-4 mt-1.5 w-44 bg-white rounded-xl shadow-lg border border-gray-100 py-1.5 z-50 text-left text-gray-700"
                                             x-cloak>
                                            <button type="button" @click="open = false" class="w-full text-left px-3.5 py-2 text-xs font-medium hover:bg-gray-50 flex items-center gap-2">
                                                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                                View receipt
                                            </button>
                                            <button type="button" @click="open = false" class="w-full text-left px-3.5 py-2 text-xs font-medium hover:bg-gray-50 flex items-center gap-2">
                                                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>
                                                Copy reference
                                            </button>
                                        </div>
                                    </td>
                                </tr>

                                <tr class="hover:bg-gray-50/60 transition-colors">
                                    <td class="px-5 py-3.5">
                                        <input type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <div class="font-bold text-gray-900">Brian Kato</div>
                                        <div class="text-[11px] text-gray-400">10:31 EAT</div>
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <div class="font-bold text-gray-900">UGX 10,000</div>
                                        <div class="text-[11px] text-gray-400">Weekly Connect</div>
                                    </td>
                                    <td class="px-4 py-3.5 font-medium text-gray-600">
                                        Airtel Money
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/60">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Matched
                                        </span>
                                    </td>
                                    <td class="px-4 py-3.5 text-right text-gray-400 relative" x-data="{ open: false }">
                                        <button @click="open = !open" class="hover:text-gray-600 p-1 rounded-lg hover:bg-gray-100 transition-colors focus:outline-none">···</button>
                                        <div x-show="open" 
                                             @click.away="open = false" 
                                             x-transition:enter="transition ease-out duration-100"
                                             x-transition:enter-start="transform opacity-0 scale-95"
                                             x-transition:enter-end="transform opacity-100 scale-100"
                                             x-transition:leave="transition ease-in duration-75"
                                             x-transition:leave-start="transform opacity-100 scale-100"
                                             x-transition:leave-end="transform opacity-0 scale-95"
                                             class="absolute right-4 mt-1.5 w-44 bg-white rounded-xl shadow-lg border border-gray-100 py-1.5 z-50 text-left text-gray-700"
                                             x-cloak>
                                            <button type="button" @click="open = false" class="w-full text-left px-3.5 py-2 text-xs font-medium hover:bg-gray-50 flex items-center gap-2">
                                                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                                View receipt
                                            </button>
                                            <button type="button" @click="open = false" class="w-full text-left px-3.5 py-2 text-xs font-medium hover:bg-gray-50 flex items-center gap-2">
                                                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>
                                                Copy reference
                                            </button>
                                        </div>
                                    </td>
                                </tr>

                                <tr class="hover:bg-gray-50/60 transition-colors">
                                    <td class="px-5 py-3.5">
                                        <input type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <div class="font-bold text-gray-900">Grace Achieng</div>
                                        <div class="text-[11px] text-gray-400">10:24 EAT</div>
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <div class="font-bold text-gray-900">UGX 150,000</div>
                                        <div class="text-[11px] text-gray-400">Business Plus</div>
                                    </td>
                                    <td class="px-4 py-3.5 font-medium text-gray-600">
                                        MTN MoMo
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-amber-50 text-amber-700 border border-amber-200/60">
                                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span> Unmatched
                                        </span>
                                    </td>
                                    <td class="px-4 py-3.5 text-right text-gray-400 relative" x-data="{ open: false }">
                                        <button @click="open = !open" class="hover:text-gray-600 p-1 rounded-lg hover:bg-gray-100 transition-colors focus:outline-none">···</button>
                                        <div x-show="open" 
                                             @click.away="open = false" 
                                             x-transition:enter="transition ease-out duration-100"
                                             x-transition:enter-start="transform opacity-0 scale-95"
                                             x-transition:enter-end="transform opacity-100 scale-100"
                                             x-transition:leave="transition ease-in duration-75"
                                             x-transition:leave-start="transform opacity-100 scale-100"
                                             x-transition:leave-end="transform opacity-0 scale-95"
                                             class="absolute right-4 mt-1.5 w-44 bg-white rounded-xl shadow-lg border border-gray-100 py-1.5 z-50 text-left text-gray-700"
                                             x-cloak>
                                            <button type="button" @click="open = false" class="w-full text-left px-3.5 py-2 text-xs font-medium hover:bg-gray-50 flex items-center gap-2">
                                                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                                View receipt
                                            </button>
                                            <button type="button" @click="open = false" class="w-full text-left px-3.5 py-2 text-xs font-medium hover:bg-gray-50 flex items-center gap-2">
                                                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>
                                                Copy reference
                                            </button>
                                        </div>
                                    </td>
                                </tr>

                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Pagination footer -->
                <div class="p-4 px-6 border-t border-gray-100 flex items-center justify-between text-xs text-gray-500">
                    <span>Showing 1–3 of 32 records</span>
                    <div class="flex items-center gap-1">
                        <button class="px-2.5 py-1 text-gray-500 hover:text-gray-900 font-medium">Previous</button>
                        <span class="w-7 h-7 rounded-lg bg-blue-50 text-blue-600 font-bold flex items-center justify-center">1</span>
                        <button class="w-7 h-7 rounded-lg text-gray-600 hover:bg-gray-50 font-medium flex items-center justify-center">2</button>
                        <span class="px-1 text-gray-300">…</span>
                        <button class="px-2.5 py-1 text-gray-700 hover:text-gray-900 font-semibold">Next</button>
                    </div>
                </div>
            </div>

            <!-- Right: Your Action List (4 cols) -->
            <div class="lg:col-span-4 bg-white rounded-2xl border border-gray-200/80 p-6 shadow-xs flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-5">
                        <h3 class="text-sm font-bold text-gray-900">Your action list</h3>
                        <a href="#" class="text-xs font-semibold text-blue-600 hover:underline">View all</a>
                    </div>

                    <div class="space-y-3">
                        
                        <!-- Action 1: Subscriptions expiring -->
                        <a href="#" class="flex items-center justify-between p-3 rounded-xl hover:bg-gray-50 transition-colors group">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-xl bg-amber-50 text-amber-700 font-bold text-sm flex items-center justify-center shrink-0">
                                    86
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-gray-900 group-hover:text-blue-600 transition-colors">Subscriptions expiring</p>
                                    <p class="text-[11px] text-gray-400 mt-0.5">In the next 7 days</p>
                                </div>
                            </div>
                            <svg class="w-4 h-4 text-gray-300 group-hover:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                        </a>

                        <!-- Action 2: Unmatched payments -->
                        <a href="#" class="flex items-center justify-between p-3 rounded-xl hover:bg-gray-50 transition-colors group">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-xl bg-blue-50 text-blue-700 font-bold text-sm flex items-center justify-center shrink-0">
                                    2
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-gray-900 group-hover:text-blue-600 transition-colors">Unmatched payments</p>
                                    <p class="text-[11px] text-gray-400 mt-0.5">UGX 210,000 to reconcile</p>
                                </div>
                            </div>
                            <svg class="w-4 h-4 text-gray-300 group-hover:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                        </a>

                        <!-- Action 3: Router offline -->
                        <a href="#" class="flex items-center justify-between p-3 rounded-xl hover:bg-gray-50 transition-colors group">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-xl bg-rose-50 text-rose-700 font-bold text-sm flex items-center justify-center shrink-0">
                                    1
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-gray-900 group-hover:text-blue-600 transition-colors">Router offline</p>
                                    <p class="text-[11px] text-gray-400 mt-0.5">Jinja Central Market</p>
                                </div>
                            </div>
                            <svg class="w-4 h-4 text-gray-300 group-hover:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                        </a>

                    </div>
                </div>

                <div class="pt-5 border-t border-gray-100 mt-6">
                    <p class="text-[11px] text-gray-400 leading-relaxed">
                        Stay ahead of renewals. Automated reminders are enabled for all active customers.
                    </p>
                </div>
            </div>

        </div>

        <!-- 6. Page Footer -->
        <div class="pt-4 border-t border-gray-200/60 flex flex-col sm:flex-row items-center justify-between gap-2 text-[11px] text-gray-400">
            <div>
                {{ $tenantTitle }} • All times in Africa/Kampala (EAT)
            </div>
            <div>
                Last synced {{ now()->format('H:i:s') }} EAT
            </div>
        </div>

    </div>
</x-app-layout>
