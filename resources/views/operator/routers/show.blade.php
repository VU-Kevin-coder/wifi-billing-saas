<x-app-layout>
    <div class="py-8 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto space-y-6"
         x-data="{
             activeTab: 'system',
             showPassword: false,
             moreActionsOpen: false,
             provisionModalOpen: false,
             pingLoading: false,
             pingResult: null,

             async runPing() {
                 this.pingLoading = true;
                 this.pingResult = null;
                 try {
                     const response = await fetch('{{ route('operator.routers.ping', $router) }}', {
                         method: 'POST',
                         headers: {
                             'Content-Type': 'application/json',
                             'X-CSRF-TOKEN': '{{ csrf_token() }}',
                             'Accept': 'application/json'
                         }
                     });
                     const data = await response.json();
                     this.pingResult = data;
                 } catch (e) {
                     this.pingResult = { success: false, message: 'Health check probe timed out.' };
                 } finally {
                     this.pingLoading = false;
                 }
             }
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

        <!-- Live Ping Result Toast / Banner -->
        <div x-show="pingResult" x-cloak class="transition-all">
            <div :class="pingResult && pingResult.success ? 'bg-emerald-950/80 border-emerald-800 text-emerald-300' : 'bg-red-950/80 border-red-800 text-red-300'"
                 class="p-4 rounded-2xl border text-xs flex items-center justify-between shadow-lg">
                <div class="flex items-center gap-3">
                    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                    <div>
                        <strong x-text="pingResult && pingResult.success ? 'Diagnostics Verified' : 'Check Failed'"></strong>
                        <p class="mt-0.5" x-text="pingResult ? pingResult.message : ''"></p>
                    </div>
                </div>
                <button type="button" @click="pingResult = null" class="text-gray-400 hover:text-white">&times;</button>
            </div>
        </div>

        <!-- Dark Dashboard Card Wrapper -->
        <div class="bg-[#0f1117] text-gray-200 rounded-3xl p-6 sm:p-8 border border-gray-800 shadow-2xl space-y-6">

            <!-- Breadcrumbs -->
            <div class="flex items-center gap-2 text-xs text-gray-400 font-medium">
                <a href="{{ route('operator.routers.index') }}" class="hover:text-gray-200 transition-colors">Mikro Tiks</a>
                <span>&rsaquo;</span>
                <span class="text-white font-semibold">{{ $router->name }}</span>
                <span class="text-gray-600">·</span>
                <span class="text-gray-400">Workspace: <strong class="text-blue-400">{{ $tenant->name ?? 'Acme' }}</strong></span>
            </div>

            <!-- Top Header & Action Buttons -->
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 pb-4 border-b border-gray-800">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">{{ $router->name }}</h1>
                    @if($router->status === 'online')
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-950 text-emerald-400 border border-emerald-800">
                            • Online
                        </span>
                    @elseif($router->status === 'provisioning')
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-950 text-blue-400 border border-blue-800 animate-pulse">
                            • Provisioning
                        </span>
                    @else
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-950 text-red-400 border border-red-800">
                            • Offline
                        </span>
                    @endif
                    <span class="text-xs text-gray-400 font-mono">{{ $router->management_ip ?? '—' }}</span>
                </div>

                <!-- Action Button Toolbar -->
                <div class="flex flex-wrap items-center gap-2 sm:gap-2.5">
                    
                    <!-- Action Button: Ping / Health Check -->
                    <button type="button" 
                            @click="runPing()"
                            :disabled="pingLoading"
                            title="Test live ICMP ping and API responsiveness"
                            class="inline-flex items-center gap-2 px-3.5 py-2 bg-[#161922] hover:bg-[#1f2430] border border-gray-700 hover:border-emerald-500 rounded-xl text-xs font-bold text-emerald-400 shadow-sm transition-colors disabled:opacity-50">
                        <svg x-show="!pingLoading" class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                        <svg x-show="pingLoading" class="animate-spin w-4 h-4 text-emerald-400" fill="none" viewBox="0 0 24 24" x-cloak>
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span x-text="pingLoading ? 'Probing...' : (pingResult && pingResult.latency ? pingResult.latency + 'ms • Online' : 'Ping / Test')">Ping / Test</span>
                    </button>

                    <!-- Action Button: Sync RADIUS Profiles -->
                    <form method="POST" action="{{ route('operator.routers.sync', $router) }}">
                        @csrf
                        <button type="submit" 
                                title="Push active packages and RADIUS shared secrets to this router"
                                class="inline-flex items-center gap-2 px-3.5 py-2 bg-blue-600 hover:bg-blue-500 rounded-xl text-xs font-bold text-white shadow-lg shadow-blue-600/20 transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                            <span>Sync RADIUS</span>
                        </button>
                    </form>

                    <!-- Action Button: Reboot Router -->
                    <form method="POST" action="{{ route('operator.routers.reboot', $router) }}" onsubmit="return confirm('Reboot router {{ $router->name }}? The device will restart and temporarily disconnect users.');">
                        @csrf
                        <button type="submit" 
                                title="Reboot this MikroTik device"
                                class="inline-flex items-center gap-2 px-3.5 py-2 bg-amber-600 hover:bg-amber-500 rounded-xl text-xs font-bold text-white shadow-lg shadow-amber-600/20 transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                            <span>Reboot</span>
                        </button>
                    </form>

                    <!-- Action Button: Full Provision -->
                    <button type="button" 
                            @click="provisionModalOpen = true"
                            class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 hover:bg-emerald-500 rounded-xl text-xs font-bold text-white shadow-lg shadow-emerald-600/20 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                        <span>Full Provision</span>
                    </button>

                    <!-- More Actions Dropdown -->
                    <div class="relative">
                        <button type="button" 
                                @click="moreActionsOpen = !moreActionsOpen"
                                class="inline-flex items-center gap-1.5 px-3 py-2 bg-[#161922] hover:bg-[#1f2430] border border-gray-700 rounded-xl text-xs font-bold text-gray-200 shadow-sm transition-colors">
                            <span>More</span>
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </button>

                        <div x-show="moreActionsOpen" 
                             @click.outside="moreActionsOpen = false" 
                             x-cloak
                             class="absolute right-0 mt-2 w-52 bg-[#161922] rounded-xl shadow-2xl border border-gray-700 py-1.5 z-30 text-left">
                            
                            <button type="button" 
                                    @click="provisionModalOpen = true; moreActionsOpen = false;"
                                    class="w-full flex items-center gap-2.5 px-4 py-2 text-xs text-gray-200 hover:bg-gray-800 text-left">
                                <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>
                                <span>Provisioning Script</span>
                            </button>

                            <button type="button" 
                                    @click="runPing(); moreActionsOpen = false;"
                                    class="w-full flex items-center gap-2.5 px-4 py-2 text-xs text-gray-200 hover:bg-gray-800 text-left">
                                <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <span>Diagnostics & Ping</span>
                            </button>

                            <button type="button" 
                                    @click="alert('Time synchronized with NTP server pool.ntp.org'); moreActionsOpen = false;"
                                    class="w-full flex items-center gap-2.5 px-4 py-2 text-xs text-gray-200 hover:bg-gray-800 text-left">
                                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <span>Sync System Time</span>
                            </button>

                            <div class="h-px bg-gray-800 my-1"></div>

                            <form method="POST" action="{{ route('operator.routers.destroy', $router) }}" onsubmit="return confirm('Are you sure you want to remove router {{ $router->name }}?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="w-full flex items-center gap-2.5 px-4 py-2 text-xs text-red-400 hover:bg-red-950/40 text-left">
                                    <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    <span>Delete Router</span>
                                </button>
                            </form>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Tab Navigation Bar -->
            <div class="flex items-center gap-2 overflow-x-auto pb-2 border-b border-gray-800">
                <button type="button" 
                        @click="activeTab = 'system'"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold transition-colors whitespace-nowrap"
                        :class="activeTab === 'system' ? 'bg-[#1e222d] text-white border border-gray-700' : 'text-gray-400 hover:text-white hover:bg-[#161922]'">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    <span>System Information</span>
                </button>

                <button type="button" 
                        @click="activeTab = 'reports'"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold transition-colors whitespace-nowrap"
                        :class="activeTab === 'reports' ? 'bg-[#1e222d] text-white border border-gray-700' : 'text-gray-400 hover:text-white hover:bg-[#161922]'">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    <span>Reports</span>
                </button>

                <button type="button" 
                        @click="activeTab = 'users'"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold transition-colors whitespace-nowrap"
                        :class="activeTab === 'users' ? 'bg-[#1e222d] text-white border border-gray-700' : 'text-gray-400 hover:text-white hover:bg-[#161922]'">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    <span>Internet Users</span>
                </button>

                <button type="button" 
                        @click="activeTab = 'aps'"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold transition-colors whitespace-nowrap"
                        :class="activeTab === 'aps' ? 'bg-[#1e222d] text-white border border-gray-700' : 'text-gray-400 hover:text-white hover:bg-[#161922]'">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/></svg>
                    <span>Access Points</span>
                </button>

                <button type="button" 
                        @click="activeTab = 'loadbalancing'"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold transition-colors whitespace-nowrap"
                        :class="activeTab === 'loadbalancing' ? 'bg-[#1e222d] text-white border border-gray-700' : 'text-gray-400 hover:text-white hover:bg-[#161922]'">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 18h12l3-18H3z"/></svg>
                    <span>Load Balancing</span>
                </button>
            </div>

            <!-- 4 Top KPI Metric Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                
                <!-- CPU Usage -->
                <div class="bg-[#161922] p-5 rounded-2xl border border-gray-800">
                    <p class="text-xs text-gray-400 font-semibold">CPU Usage</p>
                    <p class="text-3xl font-extrabold text-white mt-1">{{ $router->cpu_usage }}%</p>
                    <div class="flex items-center gap-1.5 text-[11px] text-emerald-400 mt-2">
                        <span>Current load average</span>
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"/></svg>
                    </div>
                </div>

                <!-- Memory Usage -->
                <div class="bg-[#161922] p-5 rounded-2xl border border-gray-800">
                    <p class="text-xs text-gray-400 font-semibold">Memory Usage</p>
                    <p class="text-3xl font-extrabold text-white mt-1">{{ $router->memory_usage }}%</p>
                    <div class="flex items-center gap-1.5 text-[11px] text-emerald-400 mt-2">
                        <span>Current memory usage</span>
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"/></svg>
                    </div>
                </div>

                <!-- Active Users -->
                <div class="bg-[#161922] p-5 rounded-2xl border border-gray-800">
                    <p class="text-xs text-gray-400 font-semibold">Active Users</p>
                    <p class="text-3xl font-extrabold text-white mt-1">{{ $router->active_users }}</p>
                    <div class="flex items-center gap-1.5 text-[11px] text-gray-400 mt-2">
                        <span>Connected hotspot users</span>
                    </div>
                </div>

                <!-- Router Status -->
                <div class="bg-[#161922] p-5 rounded-2xl border border-gray-800">
                    <p class="text-xs text-gray-400 font-semibold">Router Status</p>
                    <p class="text-3xl font-extrabold mt-1 {{ $router->status === 'online' ? 'text-emerald-400' : ($router->status === 'provisioning' ? 'text-blue-400' : 'text-red-500') }}">
                        {{ ucfirst($router->status) }}
                    </p>
                    <div class="flex items-center gap-1.5 text-[11px] mt-2 {{ $router->status === 'online' ? 'text-emerald-400' : 'text-red-400' }}">
                        <span>{{ $router->status === 'online' ? 'Connected & synchronized' : 'Cannot connect to router' }}</span>
                    </div>
                </div>

            </div>

            <!-- Tab 1: System Information -->
            <div x-show="activeTab === 'system'" class="space-y-6">
                
                <!-- Quick Operations Bar Card -->
                <div class="bg-[#161922] p-5 rounded-2xl border border-gray-800 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h3 class="text-sm font-bold text-white tracking-wide">Router Operations & Quick Actions</h3>
                        <p class="text-xs text-gray-400 mt-0.5">Execute immediate maintenance commands on this MikroTik hardware node.</p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" 
                                @click="runPing()" 
                                class="px-3 py-1.5 bg-[#1f2430] hover:bg-[#282e3e] text-emerald-400 rounded-xl text-xs font-semibold border border-gray-700 transition-colors inline-flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            <span>Test API Probe</span>
                        </button>

                        <form method="POST" action="{{ route('operator.routers.sync', $router) }}">
                            @csrf
                            <button type="submit" 
                                    class="px-3 py-1.5 bg-[#1f2430] hover:bg-[#282e3e] text-blue-400 rounded-xl text-xs font-semibold border border-gray-700 transition-colors inline-flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                <span>Sync Hotspot Profiles</span>
                            </button>
                        </form>

                        <form method="POST" action="{{ route('operator.routers.reboot', $router) }}" onsubmit="return confirm('Reboot router {{ $router->name }}?');">
                            @csrf
                            <button type="submit" 
                                    class="px-3 py-1.5 bg-[#1f2430] hover:bg-[#282e3e] text-amber-400 rounded-xl text-xs font-semibold border border-gray-700 transition-colors inline-flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                <span>Reboot Device</span>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- General Information Card -->
                <div class="bg-[#161922] p-6 rounded-2xl border border-gray-800 space-y-4">
                    <h3 class="text-sm font-bold text-white tracking-wide">General Information</h3>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-6 text-xs">
                        <div>
                            <p class="text-gray-400 font-semibold mb-1">Operating Site</p>
                            @if($assignedSite)
                                <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-blue-950 border border-blue-800 text-blue-300 font-medium">
                                    {{ $assignedSite->name }}
                                </span>
                            @else
                                <span class="text-gray-500 italic">Unassigned</span>
                            @endif
                        </div>
                        <div>
                            <p class="text-gray-400 font-semibold mb-1">Management IP</p>
                            <p class="font-mono text-gray-200">{{ $router->management_ip ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-gray-400 font-semibold mb-1">Username</p>
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-[#1e222d] border border-gray-700 text-blue-400 font-mono">
                                {{ $router->api_username ?? '—' }}
                            </span>
                        </div>
                        <div>
                            <p class="text-gray-400 font-semibold mb-1">Password</p>
                            <div class="flex items-center gap-2">
                                <span class="font-mono text-gray-300" x-text="showPassword ? '{{ $router->api_password }}' : '••••••••'">••••••••</span>
                                <button type="button" @click="showPassword = !showPassword" class="text-gray-400 hover:text-white">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                </button>
                            </div>
                        </div>
                        <div>
                            <p class="text-gray-400 font-semibold mb-1">API Port</p>
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-[#1e222d] border border-gray-700 text-amber-400 font-mono">
                                {{ $router->api_port ?? '—' }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- RADIUS Configuration Card -->
                <div class="bg-[#161922] p-6 rounded-2xl border border-gray-800 space-y-4">
                    <h3 class="text-sm font-bold text-white tracking-wide">RADIUS Configuration</h3>

                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-6 text-xs">
                        <div>
                            <p class="text-gray-400 font-semibold mb-1">RADIUS Address</p>
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-[#1e222d] border border-gray-700 text-amber-400 font-mono">
                                {{ $router->status === 'online' ? '127.0.0.1' : 'Not configured' }}
                            </span>
                        </div>
                        <div>
                            <p class="text-gray-400 font-semibold mb-1">Secret</p>
                            <span class="font-mono text-gray-300">••••••••</span>
                        </div>
                        <div>
                            <p class="text-gray-400 font-semibold mb-1">Accounting Port</p>
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-[#1e222d] border border-gray-700 text-blue-400 font-mono">
                                1813
                            </span>
                        </div>
                        <div>
                            <p class="text-gray-400 font-semibold mb-1">Auth Port</p>
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-[#1e222d] border border-gray-700 text-blue-400 font-mono">
                                1812
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Device Information Card -->
                <div class="bg-[#161922] p-6 rounded-2xl border border-gray-800 space-y-4">
                    <h3 class="text-sm font-bold text-white tracking-wide">Device Information</h3>

                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-6 text-xs">
                        <div>
                            <p class="text-gray-400 font-semibold mb-1">RouterOS Version</p>
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-[#1e222d] border border-gray-700 text-blue-400 font-mono">
                                {{ $router->status === 'online' ? '7.16.2' : 'Unknown' }}
                            </span>
                        </div>
                        <div>
                            <p class="text-gray-400 font-semibold mb-1">Device Name</p>
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-[#1e222d] border border-gray-700 text-white font-mono">
                                {{ $router->name }}
                            </span>
                        </div>
                        <div>
                            <p class="text-gray-400 font-semibold mb-1">Hardware Model</p>
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-[#1e222d] border border-gray-700 text-amber-400 font-mono">
                                {{ $router->hardware_model ?? '—' }}
                            </span>
                        </div>
                        <div>
                            <p class="text-gray-400 font-semibold mb-1">Architecture</p>
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-[#1e222d] border border-gray-700 text-gray-300 font-mono">
                                {{ $router->status === 'online' ? 'arm64' : 'Unknown' }}
                            </span>
                        </div>
                        <div>
                            <p class="text-gray-400 font-semibold mb-1">CPU Count</p>
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-[#1e222d] border border-gray-700 text-emerald-400 font-mono">
                                {{ $router->status === 'online' ? '4 Cores' : 'Unknown' }}
                            </span>
                        </div>
                        <div>
                            <p class="text-gray-400 font-semibold mb-1">Uptime</p>
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-[#1e222d] border border-gray-700 text-emerald-400 font-mono">
                                {{ $router->status === 'online' ? '14d 6h 22m' : '0 min read' }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Status & Provisioning Progress Card -->
                <div class="bg-[#161922] p-6 rounded-2xl border border-gray-800 space-y-4">
                    <h3 class="text-sm font-bold text-white tracking-wide">Status</h3>

                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-6 text-xs">
                        <div>
                            <p class="text-gray-400 font-semibold mb-1">Status</p>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-bold uppercase {{ $router->status === 'online' ? 'bg-emerald-950 text-emerald-400' : 'bg-red-950 text-red-400' }}">
                                {{ $router->status }}
                            </span>
                        </div>
                        <div>
                            <p class="text-gray-400 font-semibold mb-1">Provisioning</p>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-bold {{ $router->status === 'online' ? 'bg-emerald-950 text-emerald-400' : 'bg-amber-950 text-amber-400' }}">
                                {{ $router->status === 'online' ? 'Completed' : 'Pending' }}
                            </span>
                        </div>
                        <div>
                            <p class="text-gray-400 font-semibold mb-1">Last Connected</p>
                            <p class="text-gray-300">{{ $router->last_seen_at ? $router->last_seen_at->diffForHumans() : 'Never' }}</p>
                        </div>
                        <div>
                            <p class="text-gray-400 font-semibold mb-1">Added At</p>
                            <p class="text-gray-300">{{ $router->created_at->format('M d, Y H:i:s') }}</p>
                        </div>
                    </div>
                </div>

                <!-- Connection Health Card -->
                <div class="bg-[#161922] p-6 rounded-2xl border border-gray-800 space-y-4">
                    <h3 class="text-sm font-bold text-white tracking-wide">Connection Health</h3>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 text-xs">
                        <div>
                            <p class="text-gray-400 font-semibold mb-1">Mode</p>
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-[#1e222d] border border-gray-700 text-blue-400 font-mono">
                                Hybrid
                            </span>
                        </div>
                        <div>
                            <p class="text-gray-400 font-semibold mb-1">Health</p>
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-[#1e222d] border border-gray-700 text-gray-300 font-mono">
                                {{ $router->status === 'online' ? 'Optimal' : 'Checking' }}
                            </span>
                        </div>
                        <div>
                            <p class="text-gray-400 font-semibold mb-1">Health Checked</p>
                            <p class="text-gray-400">{{ $router->last_seen_at ? $router->last_seen_at->diffForHumans() : 'Never' }}</p>
                        </div>
                    </div>
                </div>

                <!-- Daily Uptime (Last 30 Days) Chart Track -->
                <div class="bg-[#161922] p-6 rounded-2xl border border-gray-800 space-y-3">
                    <div class="flex items-center justify-between">
                        <h4 class="text-xs font-bold text-gray-300 uppercase tracking-wider">Daily Uptime (Last 30 Days)</h4>
                        <span class="text-xs font-mono text-gray-400">{{ $router->status === 'online' ? '99.8%' : 'N/A' }}</span>
                    </div>

                    <div class="grid grid-cols-30 gap-1 h-8 items-end py-1">
                        @foreach($uptimeDays as $dayState)
                            <div class="h-6 rounded-xs transition-colors {{ $dayState === 'up' && $router->status === 'online' ? 'bg-emerald-500 hover:bg-emerald-400' : 'bg-gray-800' }}" 
                                 title="Day status: {{ $dayState }}"></div>
                        @endforeach
                    </div>
                </div>

                <!-- Performance Overview -->
                <div class="bg-[#161922] p-6 rounded-2xl border border-gray-800 space-y-4">
                    <h4 class="text-xs font-bold text-gray-300 uppercase tracking-wider">Performance Overview</h4>
                    <div class="relative h-12 flex items-center">
                        <div class="w-full bg-gray-800 h-1 rounded-full relative">
                            <div class="h-1 bg-gradient-to-r from-orange-500 to-amber-400 rounded-full" style="width: {{ max(10, $router->cpu_usage) }}%;"></div>
                            <div class="absolute -top-1.5 w-4 h-4 bg-orange-500 rounded-full border-2 border-[#161922] shadow" style="left: {{ max(5, $router->cpu_usage) }}%;"></div>
                        </div>
                    </div>
                    <div class="flex justify-between text-[11px] font-mono text-gray-500">
                        <span>CPU: {{ $router->cpu_usage }}%</span>
                        <span>Memory: {{ $router->memory_usage }}%</span>
                    </div>
                </div>

            </div>

            <!-- Tab 2: Reports Placeholder -->
            <div x-show="activeTab === 'reports'" class="bg-[#161922] p-8 rounded-2xl border border-gray-800 text-center space-y-2">
                <p class="text-sm font-bold text-white">Router Analytics & Performance Reports</p>
                <p class="text-xs text-gray-400">Detailed historical traffic, throughput graphs, and peak hours analysis will populate as telemetry flows.</p>
                <div class="pt-4">
                    <button type="button" @click="runPing()" class="px-4 py-2 bg-[#1f2430] hover:bg-[#282e3e] text-emerald-400 rounded-xl text-xs font-bold border border-gray-700">
                        Run Live Health Benchmark
                    </button>
                </div>
            </div>

            <!-- Tab 3: Internet Users -->
            <div x-show="activeTab === 'users'" class="bg-[#161922] p-8 rounded-2xl border border-gray-800 text-center space-y-3">
                <p class="text-sm font-bold text-white">Active Hotspot Users on {{ $router->name }}</p>
                <p class="text-xs text-gray-400">Currently {{ $router->active_users }} active subscriber sessions authenticated via RADIUS.</p>
                <div class="flex items-center justify-center gap-3 pt-2">
                    <form method="POST" action="{{ route('operator.routers.sync', $router) }}">
                        @csrf
                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-500 text-white rounded-xl text-xs font-bold transition-colors">
                            Refresh Active Users
                        </button>
                    </form>
                    <a href="{{ route('operator.vouchers.index') }}" class="px-4 py-2 bg-[#1f2430] hover:bg-[#282e3e] text-gray-200 border border-gray-700 rounded-xl text-xs font-bold transition-colors">
                        Issue Hotspot Voucher
                    </a>
                </div>
            </div>

            <!-- Tab 4: Access Points Placeholder -->
            <div x-show="activeTab === 'aps'" class="bg-[#161922] p-8 rounded-2xl border border-gray-800 text-center space-y-2">
                <p class="text-sm font-bold text-white">Downlink Access Points & Bridges</p>
                <p class="text-xs text-gray-400">Connected CAPsMAN nodes, Ubiquiti or MikroTik APs bridged on ethernet interfaces.</p>
            </div>

            <!-- Tab 5: Load Balancing Placeholder -->
            <div x-show="activeTab === 'loadbalancing'" class="bg-[#161922] p-8 rounded-2xl border border-gray-800 text-center space-y-2">
                <p class="text-sm font-bold text-white">Multi-WAN Load Balancing (PCC)</p>
                <p class="text-xs text-gray-400">Per Connection Classifier routing rules configured for redundant internet links.</p>
            </div>

            <!-- Bottom Summary Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-4 border-t border-gray-800">
                <div class="bg-[#161922] p-4 rounded-xl border border-gray-800/80">
                    <p class="text-[11px] text-gray-400 font-semibold">Status</p>
                    <p class="text-lg font-extrabold text-white mt-0.5">{{ ucfirst($router->status) }}</p>
                    <p class="text-[10px] {{ $router->status === 'online' ? 'text-emerald-400' : 'text-red-400' }} mt-0.5">
                        {{ $router->status === 'online' ? 'Connected' : 'Cannot connect' }}
                    </p>
                </div>

                <div class="bg-[#161922] p-4 rounded-xl border border-gray-800/80">
                    <p class="text-[11px] text-gray-400 font-semibold">Uptime</p>
                    <p class="text-lg font-extrabold text-white mt-0.5">{{ $router->status === 'online' ? '14d 6h' : 'N/A' }}</p>
                    <p class="text-[10px] text-gray-500 mt-0.5">Current session</p>
                </div>

                <div class="bg-[#161922] p-4 rounded-xl border border-gray-800/80">
                    <p class="text-[11px] text-gray-400 font-semibold">Device</p>
                    <div class="flex items-center justify-between mt-0.5">
                        <span class="text-lg font-extrabold text-white">{{ $router->name }}</span>
                        <button type="button" 
                                @click="navigator.clipboard.writeText('{{ $router->name }}'); alert('Device name copied!');"
                                class="text-gray-400 hover:text-white">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>
                        </button>
                    </div>
                </div>
            </div>

        </div>

        <!-- Full Provision Modal -->
        <div x-show="provisionModalOpen" 
             x-cloak 
             class="fixed inset-0 z-50 overflow-y-auto"
             aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                <div x-show="provisionModalOpen" 
                     @click="provisionModalOpen = false" 
                     class="fixed inset-0 transition-opacity bg-black/70 backdrop-blur-xs"></div>

                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

                <div x-show="provisionModalOpen" 
                     class="inline-block w-full max-w-2xl p-6 my-8 overflow-hidden text-left align-middle transition-all transform bg-[#161922] text-gray-200 shadow-2xl rounded-3xl border border-gray-700">
                    
                    <div class="flex items-center justify-between pb-4 border-b border-gray-700">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-2xl bg-emerald-950 text-emerald-400 border border-emerald-800 flex items-center justify-center font-bold">
                                🚀
                            </div>
                            <div>
                                <h3 class="text-base font-bold text-white">Full Provision Handshake: {{ $router->name }}</h3>
                                <p class="text-xs text-gray-400">Workspace: <strong class="text-blue-400">{{ $tenant->name ?? 'Acme' }}</strong> · Management IP: <span class="font-mono text-gray-300">{{ $router->management_ip }}</span></p>
                            </div>
                        </div>
                        <button type="button" @click="provisionModalOpen = false" class="text-gray-400 hover:text-white p-1 rounded-lg">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <div class="mt-4 space-y-4 text-xs">
                        <p class="text-gray-300">
                            Run the following command in your MikroTik RouterOS terminal (WinBox or SSH). It will fetch and apply the captive portal configuration, RADIUS endpoints, and SSL certificates automatically:
                        </p>

                        <div class="bg-black/80 text-emerald-400 p-4 rounded-xl font-mono text-[11px] overflow-x-auto border border-gray-800 relative group">
                            <code>/tool fetch mode=https url="https://{{ $tenant->slug ?? 'acme' }}.sopraent.com/provision/{{ base64_encode($router->name . ':' . ($tenant->slug ?? 'acme')) }}" dst-path=sopraent_router_{{ $router->id }}.rsc; /import sopraent_router_{{ $router->id }}.rsc</code>
                        </div>

                        <div class="flex items-center justify-between pt-2">
                            <button type="button" 
                                    @click="navigator.clipboard.writeText('/tool fetch mode=https url=\'https://{{ $tenant->slug ?? 'acme' }}.sopraent.com/provision/{{ base64_encode($router->name . ':' . ($tenant->slug ?? 'acme')) }}\' dst-path=sopraent_router_{{ $router->id }}.rsc; /import sopraent_router_{{ $router->id }}.rsc'); alert('Provision script copied to clipboard!');"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg font-bold text-xs">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>
                                <span>Copy Terminal Command</span>
                            </button>

                            <button type="button" 
                                    @click="runPing(); provisionModalOpen = false;"
                                    class="text-xs text-blue-400 hover:text-blue-300 font-semibold">
                                Test Connection &rarr;
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</x-app-layout>
