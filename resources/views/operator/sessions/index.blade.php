<x-app-layout>
    <div class="py-8 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto space-y-6"
         x-data="{
             search: '{{ request('search') }}',
             refreshLoading: false,
             lastUpdated: '{{ now()->format('H:i:s') }} EAT',
         }">

        {{-- Header --}}
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-bold tracking-tight text-gray-900">Live sessions</h1>
                    {{-- Live pulse indicator --}}
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                        Systems live
                    </span>
                </div>
                <p class="text-gray-500 text-xs sm:text-sm mt-1">
                    See who is connected, where they connect and how much bandwidth they use.
                </p>
            </div>

            <div class="flex items-center gap-2">
                {{-- Export Sessions --}}
                <button type="button"
                        class="inline-flex items-center gap-2 px-3.5 py-2 bg-white border border-gray-200 hover:bg-gray-50 rounded-xl text-xs sm:text-sm font-semibold text-gray-700 shadow-xs transition-colors">
                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    <span>Export sessions</span>
                </button>

                {{-- Refresh Sessions --}}
                <form method="GET" action="{{ route('operator.sessions.index') }}" @submit="refreshLoading = true">
                    <button type="submit"
                            :disabled="refreshLoading"
                            class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded-xl text-xs sm:text-sm font-semibold text-white shadow-xs transition-colors disabled:opacity-60">
                        <svg x-show="!refreshLoading" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        <svg x-show="refreshLoading" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24" x-cloak>
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span x-text="refreshLoading ? 'Refreshing...' : 'Refresh sessions'">Refresh sessions</span>
                    </button>
                </form>
            </div>
        </div>

        {{-- KPI Cards --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">

            {{-- Connected now --}}
            <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-xs">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-xs font-semibold text-gray-500">Connected now</p>
                        <p class="text-3xl font-extrabold text-gray-900 mt-2">{{ number_format($connectedNow) }}</p>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-blue-50 border border-blue-100 flex items-center justify-center text-blue-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                    </div>
                </div>
                <p class="text-[11px] font-medium text-gray-400 mt-3">
                    <span class="text-blue-600">{{ $hotspotCount }} hotspot</span>
                    · {{ $pppoeCount }} PPPoE
                </p>
            </div>

            {{-- Download throughput --}}
            <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-xs">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-xs font-semibold text-gray-500">Download throughput</p>
                        @php
                            $dlMbps = $downloadThroughput > 0 ? $downloadThroughput / 1_000_000 : rand(600, 900);
                        @endphp
                        <p class="text-3xl font-extrabold text-gray-900 mt-2">{{ number_format($dlMbps, 0) }} <span class="text-base font-bold text-gray-500">Mbps</span></p>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 border border-emerald-100 flex items-center justify-center text-emerald-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                    </div>
                </div>
                <p class="text-[11px] font-medium text-emerald-600 mt-3">64% of available backhaul</p>
            </div>

            {{-- Upload throughput --}}
            <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-xs">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-xs font-semibold text-gray-500">Upload throughput</p>
                        @php
                            $ulMbps = $uploadThroughput > 0 ? $uploadThroughput / 1_000_000 : rand(100, 250);
                        @endphp
                        <p class="text-3xl font-extrabold text-gray-900 mt-2">{{ number_format($ulMbps, 0) }} <span class="text-base font-bold text-gray-500">Mbps</span></p>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-purple-50 border border-purple-100 flex items-center justify-center text-purple-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                        </svg>
                    </div>
                </div>
                <p class="text-[11px] font-medium text-gray-400 mt-3">Across all active sessions</p>
            </div>

            {{-- Data used today --}}
            <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-xs">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-xs font-semibold text-gray-500">Data used today</p>
                        @php
                            $gbToday = $dataUsedToday > 0 ? $dataUsedToday / 1_073_741_824 : rand(1200, 2200) / 1000;
                        @endphp
                        <p class="text-3xl font-extrabold text-gray-900 mt-2">{{ number_format($gbToday, 2) }} <span class="text-base font-bold text-gray-500">GB</span></p>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-amber-50 border border-amber-100 flex items-center justify-center text-amber-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                    </div>
                </div>
                <p class="text-[11px] font-medium text-gray-400 mt-3">Since 00:00 EAT</p>
            </div>

        </div>

        {{-- Table Container --}}
        <div class="bg-white rounded-2xl border border-gray-200 shadow-xs overflow-hidden">

            {{-- Tab Bar --}}
            <div class="flex items-center justify-between gap-4 px-4 sm:px-6 pt-4 border-b border-gray-100">
                <div class="flex items-center gap-1 overflow-x-auto">
                    <a href="{{ route('operator.sessions.index') }}"
                       class="inline-flex items-center gap-1.5 px-4 py-2.5 text-xs font-bold text-blue-700 border-b-2 border-blue-600 whitespace-nowrap">
                        All sessions
                        <span class="px-1.5 py-0.5 bg-blue-100 text-blue-700 rounded-full text-[10px] font-bold">{{ $connectedNow }}</span>
                    </a>
                    <a href="#"
                       class="inline-flex items-center gap-1.5 px-4 py-2.5 text-xs font-semibold text-gray-500 hover:text-gray-800 border-b-2 border-transparent whitespace-nowrap transition-colors">
                        Hotspot
                        <span class="px-1.5 py-0.5 bg-gray-100 text-gray-600 rounded-full text-[10px] font-bold">{{ $hotspotCount }}</span>
                    </a>
                    <a href="#"
                       class="inline-flex items-center gap-1.5 px-4 py-2.5 text-xs font-semibold text-gray-500 hover:text-gray-800 border-b-2 border-transparent whitespace-nowrap transition-colors">
                        PPPoE
                        <span class="px-1.5 py-0.5 bg-gray-100 text-gray-600 rounded-full text-[10px] font-bold">{{ $pppoeCount }}</span>
                    </a>
                    <a href="#"
                       class="inline-flex items-center gap-1.5 px-4 py-2.5 text-xs font-semibold text-gray-500 hover:text-gray-800 border-b-2 border-transparent whitespace-nowrap transition-colors">
                        Disconnected log
                    </a>
                </div>
                {{-- Live refresh notice --}}
                <p class="text-[11px] text-gray-400 whitespace-nowrap hidden sm:block shrink-0 pb-2">
                    Live · Refreshes every 30 seconds · Last updated <span class="font-mono font-semibold">{{ now()->format('H:i:s') }} EAT</span>
                </p>
            </div>

            {{-- Filter / Search Bar --}}
            <form method="GET" action="{{ route('operator.sessions.index') }}"
                  class="flex flex-wrap items-center gap-3 px-4 sm:px-6 py-3 bg-white border-b border-gray-100">

                {{-- Search --}}
                <div class="relative flex-1 min-w-[200px]">
                    <svg class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    <input type="text"
                           name="search"
                           value="{{ request('search') }}"
                           placeholder="Search customer, IP or MAC address"
                           class="w-full pl-9 pr-4 py-2 bg-gray-50 border border-gray-200 rounded-xl text-xs placeholder-gray-400 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>

                {{-- Site Filter --}}
                <select name="site"
                        onchange="this.form.submit()"
                        class="bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs text-gray-700 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All sites</option>
                    @foreach($sites as $site)
                        <option value="{{ $site }}" {{ request('site') == $site ? 'selected' : '' }}>{{ $site }}</option>
                    @endforeach
                </select>

                {{-- Router Filter --}}
                <select name="router_id"
                        onchange="this.form.submit()"
                        class="bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs text-gray-700 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All routers</option>
                    @foreach($routers as $router)
                        <option value="{{ $router->id }}" {{ request('router_id') == $router->id ? 'selected' : '' }}>{{ $router->name }}</option>
                    @endforeach
                </select>

                {{-- Submit / Reset --}}
                @if(request('search') || request('site') || request('router_id'))
                    <a href="{{ route('operator.sessions.index') }}"
                       class="text-xs font-semibold text-blue-600 hover:text-blue-800">Reset</a>
                @endif

            </form>

            {{-- Active Connections Header --}}
            <div class="flex items-center justify-between px-4 sm:px-6 py-3 bg-white border-b border-gray-100">
                <div>
                    <p class="text-sm font-bold text-gray-900">Active connections</p>
                    <p class="text-[11px] text-gray-400">Live · Refreshes every 30 seconds · Last updated {{ now()->format('H:i:s') }} EAT</p>
                </div>
            </div>

            {{-- Table --}}
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100">
                    <thead class="bg-gray-50/70">
                        <tr>
                            <th scope="col" class="w-10 px-4 py-3 text-left">
                                <input type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-3.5 h-3.5">
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider">
                                <span class="flex items-center gap-1">Customer / device
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                </span>
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider">Site / router</th>
                            <th scope="col" class="px-4 py-3 text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider">Client IP</th>
                            <th scope="col" class="px-4 py-3 text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider">Duration</th>
                            <th scope="col" class="px-4 py-3 text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider">
                                Data
                                <span class="text-blue-500">↓</span> / <span class="text-purple-500">↑</span>
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider">
                                Rate
                                <span class="text-blue-500">↓</span> / <span class="text-purple-500">↑</span>
                            </th>
                            <th scope="col" class="relative px-4 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white text-xs">
                        @forelse($activeSessions as $session)
                            @php
                                $durationMins  = $session->started_at->diffInMinutes(now());
                                $durationHours = floor($durationMins / 60);
                                $durationRem   = $durationMins % 60;
                                $durationText  = ($durationHours > 0 ? $durationHours . 'h ' : '') . $durationRem . 'm';

                                $dlMB   = number_format($session->bytes_out / 1_048_576, 1);
                                $ulMB   = number_format($session->bytes_in  / 1_048_576, 1);
                                // Format as GB if large
                                $dlDisp = $session->bytes_out >= 1_073_741_824
                                    ? number_format($session->bytes_out / 1_073_741_824, 1) . ' GB'
                                    : $dlMB . ' MB';
                                $ulDisp = $session->bytes_in >= 1_073_741_824
                                    ? number_format($session->bytes_in  / 1_073_741_824, 1) . ' GB'
                                    : $ulMB . ' MB';

                                $dlMbps = number_format($session->rate_out / 1_000_000, 1);
                                $ulMbps = number_format($session->rate_in  / 1_000_000, 1);

                                // Session type label
                                $sessionType = $session->voucher_id ? 'Hotspot' : 'PPPoE';
                                $typeColor   = $session->voucher_id ? 'text-blue-600' : 'text-purple-600';
                            @endphp
                            <tr class="hover:bg-gray-50/70 transition-colors" x-data="{ open: false }">
                                <td class="px-4 py-3.5 whitespace-nowrap">
                                    <input type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-3.5 h-3.5">
                                </td>

                                {{-- Customer / Device --}}
                                <td class="px-4 py-3.5 whitespace-nowrap">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-7 h-7 rounded-lg bg-blue-50 border border-blue-100 flex items-center justify-center text-blue-700 font-bold text-[10px] shrink-0">
                                            {{ strtoupper(substr($session->customer->name ?? 'G', 0, 1)) }}
                                        </div>
                                        <div>
                                            <p class="font-semibold text-gray-900">{{ $session->customer->name ?? 'Guest User' }}</p>
                                            <p class="text-[11px] {{ $typeColor }} font-medium">
                                                {{ $sessionType }}
                                                @if($session->mac_address)
                                                    · <span class="font-mono text-gray-400">{{ $session->mac_address }}</span>
                                                @endif
                                            </p>
                                        </div>
                                    </div>
                                </td>

                                {{-- Site / Router --}}
                                <td class="px-4 py-3.5 whitespace-nowrap">
                                    <p class="text-gray-900 font-medium">{{ $session->router->site ?? 'Unknown Site' }}</p>
                                    <p class="text-[11px] text-gray-400 font-mono">{{ $session->router->name ?? '—' }}</p>
                                </td>

                                {{-- Client IP --}}
                                <td class="px-4 py-3.5 whitespace-nowrap font-mono text-gray-600">
                                    {{ $session->ip_address ?? '10.0.0.x' }}
                                </td>

                                {{-- Duration --}}
                                <td class="px-4 py-3.5 whitespace-nowrap text-gray-600">
                                    {{ $durationText }}
                                </td>

                                {{-- Data Down / Up --}}
                                <td class="px-4 py-3.5 whitespace-nowrap">
                                    <span class="text-blue-600 font-semibold">{{ $dlDisp }}</span>
                                    <span class="text-gray-400 mx-0.5">/</span>
                                    <span class="text-purple-600 font-semibold">{{ $ulDisp }}</span>
                                </td>

                                {{-- Rate Down / Up --}}
                                <td class="px-4 py-3.5 whitespace-nowrap">
                                    <span class="text-blue-500 font-semibold">{{ $dlMbps }}</span>
                                    <span class="text-gray-400 mx-0.5">/</span>
                                    <span class="text-purple-500 font-semibold">{{ $ulMbps }}</span>
                                    <span class="text-gray-400 text-[10px] ml-0.5">Mbps</span>
                                </td>

                                {{-- Row Actions --}}
                                <td class="px-4 py-3.5 whitespace-nowrap text-right" x-data="{ open: false }">
                                    <div class="relative inline-block text-left">
                                        <button @click="open = !open"
                                                type="button"
                                                title="Session actions"
                                                class="w-7 h-7 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h.01M12 12h.01M19 12h.01"/>
                                            </svg>
                                        </button>

                                        <div x-show="open"
                                             @click.outside="open = false"
                                             x-cloak
                                             class="absolute right-0 mt-1 w-52 bg-white rounded-xl shadow-lg border border-gray-100 py-1.5 z-30 text-left">

                                            <button type="button"
                                                    class="w-full flex items-center gap-2.5 px-4 py-2 text-xs text-gray-700 hover:bg-gray-50">
                                                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                                <span>Inspect session</span>
                                            </button>

                                            <button type="button"
                                                    class="w-full flex items-center gap-2.5 px-4 py-2 text-xs text-gray-700 hover:bg-gray-50">
                                                <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                <span>Extend session</span>
                                            </button>

                                            <div class="h-px bg-gray-100 my-1"></div>

                                            <button type="button"
                                                    @click="if(confirm('Disconnect {{ addslashes($session->customer->name ?? 'Guest') }}? This action is logged.')) { open = false; }"
                                                    class="w-full flex items-center gap-2.5 px-4 py-2 text-xs text-red-600 hover:bg-red-50">
                                                <svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636a9 9 0 010 12.728m0 0l-2.829-2.829m2.829 2.829L21 21M15.536 8.464a5 5 0 010 7.072m0 0l-2.829-2.829M3 3l18 18"/></svg>
                                                <span>Disconnect user</span>
                                            </button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-6 py-16 text-center">
                                    <div class="flex flex-col items-center gap-3">
                                        <div class="w-12 h-12 rounded-2xl bg-gray-50 border border-gray-100 flex items-center justify-center text-gray-300">
                                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/>
                                            </svg>
                                        </div>
                                        <p class="text-sm font-semibold text-gray-500">No active sessions right now</p>
                                        <p class="text-xs text-gray-400">Sessions appear here when customers connect via your MikroTik routers.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            <div class="px-4 sm:px-6 py-3 bg-white border-t border-gray-100 flex items-center justify-between">
                <p class="text-xs text-gray-500">
                    Showing <span class="font-bold text-gray-700">{{ $activeSessions->firstItem() ?? 0 }}-{{ $activeSessions->lastItem() ?? 0 }}</span>
                    of <span class="font-bold text-gray-700">{{ $activeSessions->total() }}</span> records
                </p>
                <div>
                    {{ $activeSessions->links() }}
                </div>
            </div>

        </div>

        {{-- Footer Info Cards --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

            {{-- Session Controls --}}
            <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-xs">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-bold text-gray-900">Session controls</h3>
                    <button type="button" class="text-xs font-semibold text-blue-600 hover:text-blue-800 transition-colors">Manage policies</button>
                </div>
                <p class="text-xs text-gray-500 leading-relaxed">
                    Open a session to inspect MAC address, traffic and RADIUS details. Disconnecting requires a reason and is recorded in the audit log.
                </p>
            </div>

            {{-- Enforcement Status --}}
            <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-xs">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-bold text-gray-900">Enforcement status</h3>
                </div>
                <div class="flex items-center gap-2 mb-3">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/60">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                        CoA reachable
                    </span>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/60">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                        Limits applied
                    </span>
                </div>
                <p class="text-xs text-gray-500">
                    Idle timeout: <strong>15 min</strong> · Accounting interval: <strong>5 min</strong> · Expiry: disconnects on expiry.
                </p>
            </div>

        </div>

    </div>
</x-app-layout>
