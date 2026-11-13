<x-app-layout>
    {{--
        Alpine state lives at the top level so the slide-over panel can
        reference the currently-selected session from anywhere in the page.
    --}}
    <div class="py-8 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto space-y-6"
         x-data="{
             refreshLoading: false,
             panelOpen: false,
             session: null,

             openPanel(s) {
                 this.session = s;
                 this.panelOpen = true;
             },
             closePanel() {
                 this.panelOpen = false;
                 this.session = null;
             },

             // Format bytes helper
             fmtBytes(b) {
                 if (b >= 1073741824) return (b / 1073741824).toFixed(2) + ' GB';
                 if (b >= 1048576)    return (b / 1048576).toFixed(1)    + ' MB';
                 if (b >= 1024)       return (b / 1024).toFixed(1)       + ' KB';
                 return b + ' B';
             },

             fmtMbps(bps) {
                 return (bps / 1000000).toFixed(1) + ' Mbps';
             }
         }"
         @keydown.escape.window="closePanel()">

        {{-- ══════════════════════════════════
             Header
        ══════════════════════════════════ --}}
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-bold tracking-tight text-gray-900">Live sessions</h1>
                    @php
                        $systemHealth = app(\App\Services\SystemHealthService::class)->current();
                    @endphp
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold border
                        {{ $systemHealth['overall'] === 'healthy' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : ($systemHealth['overall'] === 'degraded' ? 'bg-amber-50 text-amber-700 border-amber-200' : 'bg-gray-100 text-gray-600 border-gray-200') }}">
                        <span class="w-1.5 h-1.5 rounded-full {{ $systemHealth['overall'] === 'healthy' ? 'bg-emerald-500' : ($systemHealth['overall'] === 'degraded' ? 'bg-amber-500' : 'bg-gray-400') }}"></span>
                        {{ $systemHealth['overall'] === 'healthy' ? 'Systems operational' : ($systemHealth['overall'] === 'degraded' ? 'Systems degraded' : 'System status unknown') }}
                    </span>
                </div>
                <p class="text-gray-500 text-xs sm:text-sm mt-1">
                    See who is connected, where they connect and how much bandwidth they use.
                </p>
            </div>

            <div class="flex items-center gap-2">
                <button type="button"
                        class="inline-flex items-center gap-2 px-3.5 py-2 bg-white border border-gray-200 hover:bg-gray-50 rounded-xl text-xs sm:text-sm font-semibold text-gray-700 shadow-xs transition-colors">
                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    <span>Export sessions</span>
                </button>

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

        {{-- ══════════════════════════════════
             KPI Cards — 100 % from controller
        ══════════════════════════════════ --}}
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
                    @if($connectedNow > 0)
                        <span class="text-blue-600">{{ $hotspotCount }} hotspot</span> · {{ $pppoeCount }} PPPoE
                    @else
                        No active sessions
                    @endif
                </p>
            </div>

            {{-- Download throughput --}}
            <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-xs">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-xs font-semibold text-gray-500">Download throughput</p>
                        @if($downloadThroughput > 0)
                            <p class="text-3xl font-extrabold text-gray-900 mt-2">
                                {{ number_format($downloadThroughput / 1_000_000, 1) }}
                                <span class="text-base font-bold text-gray-500">Mbps</span>
                            </p>
                        @else
                            <p class="text-3xl font-extrabold text-gray-400 mt-2">—</p>
                        @endif
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 border border-emerald-100 flex items-center justify-center text-emerald-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                    </div>
                </div>
                <p class="text-[11px] font-medium text-gray-400 mt-3">Across all active sessions</p>
            </div>

            {{-- Upload throughput --}}
            <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-xs">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-xs font-semibold text-gray-500">Upload throughput</p>
                        @if($uploadThroughput > 0)
                            <p class="text-3xl font-extrabold text-gray-900 mt-2">
                                {{ number_format($uploadThroughput / 1_000_000, 1) }}
                                <span class="text-base font-bold text-gray-500">Mbps</span>
                            </p>
                        @else
                            <p class="text-3xl font-extrabold text-gray-400 mt-2">—</p>
                        @endif
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
                        @if($dataUsedToday > 0)
                            @php
                                $gbToday = $dataUsedToday / 1_073_741_824;
                                $dataDisplay = $gbToday >= 1
                                    ? number_format($gbToday, 2) . ' GB'
                                    : number_format($dataUsedToday / 1_048_576, 1) . ' MB';
                            @endphp
                            <p class="text-3xl font-extrabold text-gray-900 mt-2">{{ $dataDisplay }}</p>
                        @else
                            <p class="text-3xl font-extrabold text-gray-400 mt-2">—</p>
                        @endif
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-amber-50 border border-amber-100 flex items-center justify-center text-amber-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                    </div>
                </div>
                <p class="text-[11px] font-medium text-gray-400 mt-3">Since 00:00 {{ now()->timezone->toOffsetName() }}</p>
            </div>

        </div>

        {{-- ══════════════════════════════════
             Table Container
        ══════════════════════════════════ --}}
        <div class="bg-white rounded-2xl border border-gray-200 shadow-xs overflow-hidden">

            {{-- Tab bar --}}
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
                <p class="text-[11px] text-gray-400 whitespace-nowrap hidden sm:block shrink-0 pb-2">
                    Live · Last updated <span class="font-mono font-semibold">{{ now()->format('H:i:s') }} EAT</span>
                </p>
            </div>

            {{-- Filter bar --}}
            <form method="GET" action="{{ route('operator.sessions.index') }}"
                  class="flex flex-wrap items-center gap-3 px-4 sm:px-6 py-3 bg-white border-b border-gray-100">

                <div class="relative flex-1 min-w-[200px]">
                    <svg class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    <input type="text" name="search" value="{{ request('search') }}"
                           placeholder="Search customer, IP or MAC address"
                           class="w-full pl-9 pr-4 py-2 bg-gray-50 border border-gray-200 rounded-xl text-xs placeholder-gray-400 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>

                <select name="site_id" onchange="this.form.submit()"
                        class="bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs text-gray-700 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All sites</option>
                    @foreach($sites as $site)
                        <option value="{{ $site->id }}" @selected(request('site_id') == $site->id)>{{ $site->name }}</option>
                    @endforeach
                </select>

                <select name="router_id" onchange="this.form.submit()"
                        class="bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs text-gray-700 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All routers</option>
                    @foreach($routers as $router)
                        <option value="{{ $router->id }}" {{ request('router_id') == $router->id ? 'selected' : '' }}>{{ $router->name }}</option>
                    @endforeach
                </select>

                @if(request('search') || request('site_id') || request('router_id'))
                    <a href="{{ route('operator.sessions.index') }}" class="text-xs font-semibold text-blue-600 hover:text-blue-800">Reset</a>
                @endif
            </form>

            {{-- Active connections subheader --}}
            <div class="flex items-center justify-between px-4 sm:px-6 py-3 border-b border-gray-100">
                <div>
                    <p class="text-sm font-bold text-gray-900">Active connections</p>
                    <p class="text-[11px] text-gray-400">Click any row to inspect the session · Last updated {{ now()->format('H:i:s') }} EAT</p>
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
                            <th scope="col" class="px-4 py-3 text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider">Customer / device</th>
                            <th scope="col" class="px-4 py-3 text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider">Site / router</th>
                            <th scope="col" class="px-4 py-3 text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider">Client IP</th>
                            <th scope="col" class="px-4 py-3 text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider">Duration</th>
                            <th scope="col" class="px-4 py-3 text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider">
                                Data <span class="text-blue-500">↓</span> / <span class="text-purple-500">↑</span>
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider">
                                Rate <span class="text-blue-500">↓</span> / <span class="text-purple-500">↑</span>
                            </th>
                            <th scope="col" class="relative px-4 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white text-xs">
                        @forelse($activeSessions as $session)
                            @php
                                $dMins  = $session->started_at->diffInMinutes(now());
                                $dHrs   = floor($dMins / 60);
                                $dRem   = $dMins % 60;
                                $dur    = ($dHrs > 0 ? $dHrs . 'h ' : '') . $dRem . 'm';

                                $dlDisp = $session->bytes_out >= 1_073_741_824
                                    ? number_format($session->bytes_out / 1_073_741_824, 2) . ' GB'
                                    : number_format($session->bytes_out / 1_048_576, 1) . ' MB';
                                $ulDisp = $session->bytes_in >= 1_073_741_824
                                    ? number_format($session->bytes_in / 1_073_741_824, 2) . ' GB'
                                    : number_format($session->bytes_in / 1_048_576, 1) . ' MB';

                                $dlMbps = number_format($session->rate_out / 1_000_000, 1);
                                $ulMbps = number_format($session->rate_in  / 1_000_000, 1);

                                $sessionType = $session->voucher_id ? 'Hotspot' : 'PPPoE';
                                $typeColor   = $session->voucher_id ? 'text-blue-600' : 'text-purple-600';

                                $customerName = $session->customer?->name 
                                    ?? ($session->voucher ? 'Voucher ' . $session->voucher->code : 'Wi-Fi Guest');

                                $siteAgent = $session->site?->agent ?? $session->router?->assignedSite?->agent;
                                $siteAgentOnline = $siteAgent
                                    && ! $siteAgent->revoked_at
                                    && $siteAgent->last_seen_at?->greaterThanOrEqualTo(now()->subMinutes(2));
                                $banCommand = $deviceBanCommands->get($session->id);

                                // Build the JS-safe session object for the side panel
                                $sessionJs = json_encode([
                                    'id'          => $session->id,
                                    'customer'    => $customerName,
                                    'email'       => $session->customer?->email ?? '—',
                                    'type'        => $sessionType,
                                    'voucher'     => $session->voucher?->code,
                                    'mac'         => $session->mac_address ?? '—',
                                    'ip'          => $session->ip_address ?? '—',
                                    'router'      => $session->router?->name ?? '—',
                                    'site'        => $session->site?->name ?? $session->router?->site ?? '—',
                                    'package'     => $session->package?->name ?? '—',
                                    'started'     => $session->started_at->format('Y-m-d H:i:s'),
                                    'expires'     => $session->expires_at?->format('Y-m-d H:i:s') ?? '—',
                                    'duration'    => $dur,
                                    'dl'          => $dlDisp,
                                    'ul'          => $ulDisp,
                                    'dlMbps'      => $dlMbps,
                                    'ulMbps'      => $ulMbps,
                                    'bytes_in'    => $session->bytes_in,
                                    'bytes_out'   => $session->bytes_out,
                                    'rate_in'     => $session->rate_in,
                                    'rate_out'    => $session->rate_out,
                                    'hasVoucher' => (bool) $session->voucher,
                                    'hasRouter' => (bool) $session->router,
                                    'disconnecting' => $session->status === 'disconnecting',
                                    'pendingRevocation' => (bool) $session->pending_revocation,
                                    'pendingRevocationStatus' => $session->pending_revocation_status,
                                    'pendingDeviceBan' => (bool) $session->pending_device_ban,
                                    'siteAgentOnline' => (bool) $siteAgentOnline,
                                    'banCommandStatus' => $banCommand?->status,
                                    'banCommandFailure' => $banCommand?->failure_reason,
                                    'disconnectUrl' => route('operator.sessions.disconnect', $session->id),
                                    'banUrl' => route('operator.sessions.ban-device', $session->id),
                                ]);
                            @endphp
                            <tr class="hover:bg-blue-50/40 cursor-pointer transition-colors group"
                                @click="openPanel({{ $sessionJs }})">

                                {{-- Checkbox (stop click propagation so row-click doesn't fire) --}}
                                <td class="px-4 py-3.5 whitespace-nowrap" @click.stop>
                                    <input type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-3.5 h-3.5">
                                </td>

                                {{-- Customer / Device --}}
                                <td class="px-4 py-3.5 whitespace-nowrap">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-7 h-7 rounded-lg bg-blue-50 border border-blue-100 flex items-center justify-center text-blue-700 font-bold text-[10px] shrink-0">
                                            {{ strtoupper(substr($customerName, 0, 1)) }}
                                        </div>
                                        <div>
                                            <p class="font-semibold text-gray-900 group-hover:text-blue-700 transition-colors">
                                                {{ $customerName }}
                                            </p>
                                            <p class="text-[11px] {{ $typeColor }} font-medium">
                                                {{ $sessionType }}
                                                @if($session->voucher)
                                                    · <span class="font-mono text-gray-400">{{ $session->voucher->code }}</span>
                                                @endif
                                                @if($session->mac_address)
                                                    · <span class="font-mono text-gray-400" title="Reported by the client; router-verified MAC is used for device bans">{{ $session->mac_address }} (reported)</span>
                                                @endif
                                                @if($session->pending_revocation && $session->pending_revocation_status === 'expired')
                                                    · <span class="font-semibold text-amber-700">Expired · awaiting router enforcement</span>
                                                @elseif($session->status === 'disconnecting')
                                                    · <span class="font-semibold text-amber-700">Awaiting router confirmation</span>
                                                @endif
                                            </p>
                                        </div>
                                    </div>
                                </td>

                                {{-- Site / Router --}}
                                <td class="px-4 py-3.5 whitespace-nowrap">
                                    <p class="text-gray-900 font-medium">{{ $session->site?->name ?? $session->router?->site ?? '—' }}</p>
                                    <p class="text-[11px] text-gray-400 font-mono">{{ $session->router?->name ?? '—' }}</p>
                                </td>

                                {{-- Client IP --}}
                                <td class="px-4 py-3.5 whitespace-nowrap font-mono text-gray-600">
                                    {{ $session->ip_address ?? '—' }}
                                </td>

                                {{-- Duration --}}
                                <td class="px-4 py-3.5 whitespace-nowrap text-gray-600">{{ $dur }}</td>

                                {{-- Data --}}
                                <td class="px-4 py-3.5 whitespace-nowrap">
                                    <span class="text-blue-600 font-semibold">{{ $dlDisp }}</span>
                                    <span class="text-gray-400 mx-0.5">/</span>
                                    <span class="text-purple-600 font-semibold">{{ $ulDisp }}</span>
                                </td>

                                {{-- Rate --}}
                                <td class="px-4 py-3.5 whitespace-nowrap">
                                    <span class="text-blue-500 font-semibold">{{ $dlMbps }}</span>
                                    <span class="text-gray-400 mx-0.5">/</span>
                                    <span class="text-purple-500 font-semibold">{{ $ulMbps }}</span>
                                    <span class="text-gray-400 text-[10px] ml-0.5">Mbps</span>
                                </td>

                                {{-- Open panel hint --}}
                                <td class="px-4 py-3.5 whitespace-nowrap text-right" @click.stop>
                                    <button type="button"
                                            @click="openPanel({{ $sessionJs }})"
                                            title="Inspect session"
                                            class="w-7 h-7 rounded-lg flex items-center justify-center text-gray-300 group-hover:text-blue-500 transition-colors">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                        </svg>
                                    </button>
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
                    Showing <span class="font-bold text-gray-700">{{ $activeSessions->firstItem() ?? 0 }}–{{ $activeSessions->lastItem() ?? 0 }}</span>
                    of <span class="font-bold text-gray-700">{{ $activeSessions->total() }}</span> records
                </p>
                <div>{{ $activeSessions->links() }}</div>
            </div>

        </div>

        <section class="bg-white rounded-2xl border border-gray-100 shadow-xs overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100">
                <h2 class="text-sm font-bold text-gray-900">Blocked devices</h2>
                <p class="mt-1 text-xs text-gray-500">Showing the latest 100 records. MAC blocks apply only to the selected router, can be spoofed, and do not void the associated voucher.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-gray-50 text-[10px] uppercase tracking-wider text-gray-500">
                        <tr>
                            <th class="px-5 py-3 font-semibold">Device MAC</th>
                            <th class="px-5 py-3 font-semibold">Router</th>
                            <th class="px-5 py-3 font-semibold">Status</th>
                            <th class="px-5 py-3 font-semibold">Reason / latest error</th>
                            <th class="px-5 py-3 font-semibold">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($blockedDevices as $device)
                            @php
                                $deviceStatusClass = match ($device->status) {
                                    'blocked' => 'bg-red-50 text-red-700',
                                    'unblocked' => 'bg-gray-100 text-gray-600',
                                    'ban_failed', 'unban_failed' => 'bg-red-50 text-red-700',
                                    default => 'bg-amber-50 text-amber-800',
                                };
                            @endphp
                            <tr>
                                <td class="px-5 py-3 font-mono text-gray-800">{{ $device->mac_address ?? 'Awaiting router MAC' }}</td>
                                <td class="px-5 py-3 text-gray-700">{{ $device->router?->name ?? '—' }}</td>
                                <td class="px-5 py-3">
                                    <span class="rounded-full px-2 py-1 font-semibold {{ $deviceStatusClass }}">{{ str_replace('_', ' ', $device->status) }}</span>
                                </td>
                                <td class="px-5 py-3 text-gray-600">
                                    <span>{{ $device->last_error ?: $device->reason ?: '—' }}</span>
                                </td>
                                <td class="px-5 py-3" x-data="{
                                    loading: false,
                                    pending: false,
                                    message: '',
                                    agentOnline: true,
                                    async unblockDevice() {
                                        const reason = await window.confirmWithReason(
                                            'Remove this MAC block from the router? The device will be allowed to connect again.',
                                            { title: 'Unban device', inputLabel: 'Reason for unblocking this MAC' }
                                        );
                                        if (reason === null) return;

                                        this.loading = true;
                                        try {
                                            const result = await window.apiFetch('{{ route('operator.blocked-devices.unban', $device->id) }}', {
                                                method: 'POST',
                                                headers: { 'Content-Type': 'application/json' },
                                                body: JSON.stringify({ reason }),
                                                feedback: false,
                                            });
                                            if (result.success) {
                                                this.pending = true;
                                                this.message = result.message;
                                                this.agentOnline = result.site_agent_online;
                                                window.toast.info(result.message);
                                            } else {
                                                window.toast.error(result.message || 'Unban was not queued.');
                                            }
                                        } catch (error) {
                                            window.toast.error(error.message || 'Network error.');
                                        } finally {
                                            this.loading = false;
                                        }
                                    },
                                }">
                                    @if(in_array($device->status, ['blocked', 'unban_pending', 'unban_failed'], true))
                                        <button type="button"
                                                x-show="!pending"
                                                :disabled="loading"
                                                @click="unblockDevice()"
                                                class="font-semibold text-blue-700 hover:text-blue-900 disabled:opacity-50">
                                            <span x-text="loading ? 'Queueing…' : ( '{{ $device->status }}' === 'blocked' ? 'Unban' : 'Retry unban' )">Unban</span>
                                        </button>
                                        <span x-show="pending" class="text-amber-800">
                                            Unban pending<span x-show="!agentOnline">; site agent offline</span>
                                            <span x-show="message" x-text="': ' + message"></span>
                                        </span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-8 text-center text-gray-500">No blocked devices have been recorded.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        {{-- ══════════════════════════════════
             Footer cards
        ══════════════════════════════════ --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-xs">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-bold text-gray-900">Session controls</h3>
                    <button type="button" class="text-xs font-semibold text-blue-600 hover:text-blue-800 transition-colors">Manage policies</button>
                </div>
                <p class="text-xs text-gray-500 leading-relaxed">
                    Click any row to inspect session details. Voiding a voucher and blocking a router-verified MAC require a reason and are recorded in the audit log.
                </p>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-xs">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-bold text-gray-900">Enforcement status</h3>
                </div>
                <div class="flex items-center gap-2 mb-3">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-blue-50 text-blue-700 border border-blue-200/60">
                        Site-agent command queue
                    </span>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-50 text-amber-800 border border-amber-200/60">
                        Router confirmation required
                    </span>
                </div>
                <p class="text-xs text-gray-500">
                    Commands are retried while pending. A queued action is not proof of enforcement; verify the router-confirmed status before treating a customer as disconnected or blocked.
                </p>
            </div>
        </div>

    </div>{{-- /page wrapper --}}


    {{-- ══════════════════════════════════════════════════════════
         SESSION DETAIL SIDE OVERLAY
         Opens when the user clicks any row. Slides in from the right.
    ══════════════════════════════════════════════════════════ --}}

    {{-- Backdrop --}}
    <div x-show="panelOpen"
         x-cloak
         x-transition:enter="ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="closePanel()"
         class="fixed inset-0 bg-gray-900/40 backdrop-blur-sm z-40">
    </div>

    {{-- Panel --}}
    <div x-show="panelOpen"
         x-cloak
         x-transition:enter="transform transition ease-out duration-300"
         x-transition:enter-start="translate-x-full"
         x-transition:enter-end="translate-x-0"
         x-transition:leave="transform transition ease-in duration-200"
         x-transition:leave-start="translate-x-0"
         x-transition:leave-end="translate-x-full"
         class="fixed inset-y-0 right-0 w-full sm:w-[480px] bg-white shadow-2xl z-50 flex flex-col overflow-hidden">

        {{-- Panel header --}}
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 shrink-0">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-blue-50 border border-blue-100 flex items-center justify-center text-blue-700 font-bold text-sm"
                     x-text="session ? session.customer.charAt(0).toUpperCase() : '?'"></div>
                <div>
                    <p class="text-sm font-bold text-gray-900" x-text="session ? session.customer : '—'"></p>
                    <p class="text-[11px] text-gray-400" x-text="session ? session.type + ' · ' + session.ip : '—'"></p>
                </div>
            </div>
            <button @click="closePanel()" type="button"
                    class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        {{-- Panel body (scrollable) --}}
        <div class="flex-1 overflow-y-auto px-6 py-5 space-y-6" x-show="session">

            {{-- Status badge --}}
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold border"
                      :class="session?.pendingRevocation || session?.disconnecting ? 'bg-amber-50 text-amber-800 border-amber-200' : 'bg-emerald-50 text-emerald-700 border-emerald-200'">
                    <span class="w-1.5 h-1.5 rounded-full animate-pulse"
                          :class="session?.pendingRevocation || session?.disconnecting ? 'bg-amber-500' : 'bg-emerald-500'"></span>
                    <span x-text="session?.pendingRevocationStatus === 'expired' ? 'Expired · enforcement pending' : (session?.disconnecting ? 'Enforcement pending' : 'Active')">Active</span>
                </span>
                <span class="text-xs text-gray-400" x-text="session ? 'Session #' + session.id : ''"></span>
            </div>

            {{-- Identity --}}
            <div>
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-2">Identity</p>
                <div class="bg-gray-50 rounded-xl border border-gray-100 divide-y divide-gray-100 text-xs">
                    <div class="flex items-center justify-between px-4 py-2.5">
                        <span class="text-gray-500">Customer</span>
                        <span class="font-semibold text-gray-900" x-text="session?.customer ?? '—'"></span>
                    </div>
                    <div class="flex items-center justify-between px-4 py-2.5">
                        <span class="text-gray-500">Email</span>
                        <span class="font-mono text-gray-700" x-text="session?.email ?? '—'"></span>
                    </div>
                    <div class="flex items-center justify-between px-4 py-2.5">
                        <span class="text-gray-500">Package</span>
                        <span class="font-semibold text-gray-900" x-text="session?.package ?? '—'"></span>
                    </div>
                    <div class="flex items-center justify-between px-4 py-2.5">
                        <span class="text-gray-500">Session type</span>
                        <span class="font-semibold text-gray-900" x-text="session?.type ?? '—'"></span>
                    </div>
                </div>
            </div>

            {{-- Network --}}
            <div>
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-2">Network</p>
                <div class="bg-gray-50 rounded-xl border border-gray-100 divide-y divide-gray-100 text-xs">
                    <div class="flex items-center justify-between px-4 py-2.5">
                        <span class="text-gray-500">Client IP</span>
                        <span class="font-mono font-semibold text-gray-900" x-text="session?.ip ?? '—'"></span>
                    </div>
                    <div class="flex items-center justify-between px-4 py-2.5">
                        <span class="text-gray-500">Client-reported MAC</span>
                        <span class="font-mono text-gray-700" x-text="session?.mac ?? '—'"></span>
                    </div>
                    <div class="flex items-center justify-between px-4 py-2.5">
                        <span class="text-gray-500">Router</span>
                        <span class="font-semibold text-gray-900" x-text="session?.router ?? '—'"></span>
                    </div>
                    <div class="flex items-center justify-between px-4 py-2.5">
                        <span class="text-gray-500">Site</span>
                        <span class="font-semibold text-gray-900" x-text="session?.site ?? '—'"></span>
                    </div>
                </div>
            </div>

            {{-- Timing --}}
            <div>
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-2">Timing</p>
                <div class="bg-gray-50 rounded-xl border border-gray-100 divide-y divide-gray-100 text-xs">
                    <div class="flex items-center justify-between px-4 py-2.5">
                        <span class="text-gray-500">Connected at</span>
                        <span class="font-mono text-gray-700" x-text="session?.started ?? '—'"></span>
                    </div>
                    <div class="flex items-center justify-between px-4 py-2.5">
                        <span class="text-gray-500">Expires at</span>
                        <span class="font-mono text-gray-700" x-text="session?.expires ?? '—'"></span>
                    </div>
                    <div class="flex items-center justify-between px-4 py-2.5">
                        <span class="text-gray-500">Duration</span>
                        <span class="font-semibold text-gray-900" x-text="session?.duration ?? '—'"></span>
                    </div>
                </div>
            </div>

            {{-- Bandwidth --}}
            <div>
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-2">Bandwidth</p>
                <div class="grid grid-cols-2 gap-3">
                    <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 text-center">
                        <p class="text-[11px] font-semibold text-blue-500 mb-1">↓ Download</p>
                        <p class="text-lg font-extrabold text-blue-700" x-text="session?.dl ?? '—'"></p>
                        <p class="text-[11px] text-blue-400 mt-0.5" x-text="(session?.dlMbps ?? '0') + ' Mbps'"></p>
                    </div>
                    <div class="bg-purple-50 border border-purple-100 rounded-xl p-4 text-center">
                        <p class="text-[11px] font-semibold text-purple-500 mb-1">↑ Upload</p>
                        <p class="text-lg font-extrabold text-purple-700" x-text="session?.ul ?? '—'"></p>
                        <p class="text-[11px] text-purple-400 mt-0.5" x-text="(session?.ulMbps ?? '0') + ' Mbps'"></p>
                    </div>
                </div>
            </div>

        </div>

        {{-- Panel footer actions --}}
        <div class="shrink-0 border-t border-gray-100 px-6 py-4 bg-white space-y-3" x-show="session">

            {{-- Extend Session (placeholder action) --}}
            <button type="button"
                    class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-blue-600 hover:bg-blue-700 rounded-xl text-sm font-semibold text-white transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                Extend Session
            </button>

            <div x-show="session?.hasVoucher && session?.hasRouter"
                 class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                <p x-show="!session?.siteAgentOnline">
                    <strong>Site agent offline.</strong> Router actions will wait for the agent to reconnect. The customer may remain online until the router confirms enforcement.
                </p>
                <p x-show="session?.siteAgentOnline">
                    Router actions are asynchronous; access is not considered revoked until the site agent confirms the router change.
                </p>
            </div>

            {{-- Void voucher and disconnect --}}
            <div x-data="{
                sessionId: null,
                loading: false,
                pending: false,
                message: '',
                async revokeAccess() {
                    const requestSessionId = session?.id;
                    const reason = await window.confirmWithReason(
                        'This permanently voids voucher ' + (session?.voucher ?? '') + ' and asks the router to disable its user and kick the active session.',
                        { title: 'Void voucher & disconnect', inputLabel: 'Reason for revocation', confirmButtonText: 'Void voucher & disconnect' }
                    );
                    if (reason === null || session?.id !== requestSessionId) return;

                    this.loading = true;
                    try {
                        const result = await window.apiFetch(session.disconnectUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ reason }),
                            feedback: false,
                        });
                        if (session?.id !== requestSessionId) return;
                        if (!result.success) {
                            window.toast.error(result.message || 'Failed to disconnect.');
                            return;
                        }

                        this.pending = true;
                        this.message = result.message;
                        session.siteAgentOnline = result.site_agent_online;
                        session.pendingRevocation = true;
                        session.disconnecting = true;
                        window.toast[result.site_agent_online ? 'info' : 'warning'](result.message);
                    } catch (error) {
                        window.toast.error(error.message || 'Network error.');
                    } finally {
                        this.loading = false;
                    }
                },
            }"
            x-effect="if (sessionId !== session?.id) { sessionId = session?.id; loading = false; pending = false; message = ''; }">
                <template x-if="session?.pendingRevocation">
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                        <span x-text="session?.pendingRevocationStatus === 'expired' ? 'Session time has expired; waiting for the router to enforce disconnection.' : 'Voucher revoked in the app; waiting for the router to disable the user and disconnect the session.'"></span>
                    </div>
                </template>
                <template x-if="!session?.pendingRevocation && !session?.pendingDeviceBan && !pending && session?.hasVoucher && session?.hasRouter">
                    <button type="button"
                            @click="revokeAccess()"
                            :disabled="loading"
                            class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-white border border-red-200 hover:bg-red-50 rounded-xl text-sm font-semibold text-red-600 transition-colors">
                        <svg x-show="!session?.siteAgentOnline" class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" title="Site agent offline; router enforcement will be delayed" x-cloak>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v4m0 4h.01M10.3 3.86L1.82 18a2 2 0 001.7 3h16.96a2 2 0 001.7-3L13.7 3.86a2 2 0 00-3.4 0z"/>
                        </svg>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636a9 9 0 010 12.728m0 0l-2.829-2.829m2.829 2.829L21 21M15.536 8.464a5 5 0 010 7.072m0 0l-2.829-2.829M3 3l18 18"/>
                        </svg>
                        Void voucher & disconnect
                    </button>
                </template>

                <template x-if="pending">
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                        <strong>Revocation pending.</strong>
                        <span x-text="message"></span>
                        <p x-show="!session?.siteAgentOnline" class="mt-1 font-semibold">Site agent offline; router enforcement waits until it reconnects.</p>
                    </div>
                </template>

                <template x-if="!session?.hasVoucher || !session?.hasRouter">
                    <p class="text-xs text-gray-500">This session has no router-linked voucher, so it cannot be revoked from this page.</p>
                </template>
            </div>

            {{-- Ban the router-verified MAC without voiding the voucher --}}
            <div x-data="{
                sessionId: null,
                loading: false,
                pending: false,
                message: '',
                async banDevice() {
                    const requestSessionId = session?.id;
                    const reason = await window.confirmWithReason(
                        'The site agent will block the router-reported MAC on this router and disconnect it. This does not void the voucher; another device may still use it. MAC addresses can be spoofed.',
                        { title: 'Ban device (MAC)', inputLabel: 'Reason for device ban', confirmButtonText: 'Ban device' }
                    );
                    if (reason === null || session?.id !== requestSessionId) return;

                    this.loading = true;
                    try {
                        const result = await window.apiFetch(session.banUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ reason }),
                            feedback: false,
                        });
                        if (session?.id !== requestSessionId) return;
                        if (!result.success) {
                            window.toast.error(result.message || 'Device ban was not queued.');
                            return;
                        }

                        this.pending = true;
                        this.message = result.message;
                        session.siteAgentOnline = result.site_agent_online;
                        session.pendingDeviceBan = true;
                        session.disconnecting = true;
                        window.toast[result.site_agent_online ? 'info' : 'warning'](result.message);
                    } catch (error) {
                        window.toast.error(error.message || 'Network error.');
                    } finally {
                        this.loading = false;
                    }
                },
            }"
            x-effect="if (sessionId !== session?.id) { sessionId = session?.id; loading = false; pending = false; message = ''; }">
                <template x-if="session?.pendingDeviceBan">
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                        Device ban pending router confirmation.
                        <span x-show="session?.banCommandStatus === 'failed'" class="block mt-1 text-red-700">
                            Last attempt failed: <span x-text="session?.banCommandFailure"></span>. Automatic retry is scheduled.
                        </span>
                    </div>
                </template>
                <template x-if="!session?.pendingDeviceBan && !session?.pendingRevocation && !pending && session?.hasVoucher && session?.hasRouter">
                    <button type="button"
                            @click="banDevice()"
                            :disabled="loading"
                            class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-red-700 hover:bg-red-800 rounded-xl text-sm font-semibold text-white transition-colors">
                        <svg x-show="!session?.siteAgentOnline" class="w-4 h-4 text-amber-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" title="Site agent offline; router enforcement will be delayed" x-cloak>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v4m0 4h.01M10.3 3.86L1.82 18a2 2 0 001.7 3h16.96a2 2 0 001.7-3L13.7 3.86a2 2 0 00-3.4 0z"/>
                        </svg>
                        Ban device (MAC)
                    </button>
                </template>

                <template x-if="pending">
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                        <strong>Device ban pending.</strong>
                        <span x-text="message"></span>
                        <p x-show="!session?.siteAgentOnline" class="mt-1 font-semibold">Site agent offline; router enforcement waits until it reconnects.</p>
                    </div>
                </template>
            </div>

        </div>

    </div>

</x-app-layout>
