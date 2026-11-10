<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-gray-900 leading-tight">
                Settings
            </h2>
            <a href="{{ route('portal.index', ['tenant' => $tenant->slug]) }}" 
               target="_blank" 
               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 shadow-sm transition-colors">
                <svg class="w-3.5 h-3.5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                </svg>
                View Live Portal
            </a>
        </div>
    </x-slot>

    <div class="py-8" x-data="{ 
        testingConnection: false,
        testResult: null,
        testMessage: '',
        
        async runTest() {
            this.testingConnection = true;
            this.testResult = null;
            this.testMessage = '';

            try {
                const data = await window.apiFetch('{{ route('operator.settings.router.test') }}', { method: 'POST' });
                this.testResult = data.success ? 'success' : 'error';
                this.testMessage = data.message;
            } catch (err) {
                this.testResult = 'error';
                this.testMessage = err.message || 'Network error while attempting router ping.';
            } finally {
                this.testingConnection = false;
            }
        }
    }">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            
            <!-- Navigation Tabs -->
            <div class="border-b border-gray-200 mb-8 bg-white px-6 rounded-xl shadow-sm">
                <nav class="-mb-px flex space-x-8">
                    <a href="{{ route('operator.settings.index') }}" class="border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                        General Settings
                    </a>
                    <a href="{{ route('operator.settings.router') }}" class="border-blue-600 text-blue-600 whitespace-nowrap py-4 px-1 border-b-2 font-semibold text-sm flex items-center gap-2">
                        <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/>
                        </svg>
                        MikroTik Router
                    </a>
                    <a href="{{ route('operator.settings.index', ['tab' => 'portal']) }}" class="border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center gap-2">
                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                        Captive Portal
                    </a>
                </nav>
            </div>

            <!-- Flash Success / Error -->
            @if(session('status'))
                <div class="mb-6 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-xl flex items-center justify-between shadow-sm">
                    <div class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                        <span class="text-sm font-semibold">{{ session('status') }}</span>
                    </div>
                    <button type="button" @click="$el.parentElement.remove()" class="text-green-500 hover:text-green-700">&times;</button>
                </div>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                
                <!-- Main Configuration Form (2 cols) -->
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-2xl border border-gray-200/80 shadow-sm p-6 sm:p-8">
                        <div class="flex items-center justify-between pb-6 mb-6 border-b border-gray-100">
                            <div>
                                <h3 class="text-base font-bold text-gray-900">MikroTik Router API Connection</h3>
                                <p class="text-xs text-gray-500 mt-1">Connect your dedicated MikroTik router for automatic hotspot user creation and active session tracking.</p>
                            </div>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold {{ $tenant->mikrotik_host ? 'bg-emerald-50 text-emerald-700 border border-emerald-200/80' : 'bg-gray-100 text-gray-600' }}">
                                <span class="w-1.5 h-1.5 rounded-full {{ $tenant->mikrotik_host ? 'bg-emerald-500' : 'bg-gray-400' }}"></span>
                                {{ $tenant->mikrotik_host ? 'Configured' : 'Not Connected' }}
                            </span>
                        </div>

                        <form action="{{ route('operator.settings.router.update') }}" method="POST">
                            @csrf
                            @method('PUT')

                            <div class="space-y-6 text-sm">
                                
                                <!-- Router Host / IP -->
                                <div>
                                    <label for="mikrotik_host" class="block font-semibold text-gray-800 mb-1.5">
                                        Router IP Address or Hostname <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" 
                                           id="mikrotik_host" 
                                           name="mikrotik_host" 
                                           value="{{ old('mikrotik_host', $tenant->mikrotik_host) }}"
                                           placeholder="e.g. 192.168.88.1 or router.yourdomain.com"
                                           class="w-full rounded-xl border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500 font-mono">
                                    <p class="text-xs text-gray-400 mt-1">Public IP, DDNS hostname, or WireGuard/VPN IP reachable by the SaaS server.</p>
                                    @error('mikrotik_host')
                                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                                    @enderror
                                </div>

                                <!-- API Port -->
                                <div>
                                    <label for="mikrotik_port" class="block font-semibold text-gray-800 mb-1.5">
                                        API Port
                                    </label>
                                    <input type="number" 
                                           id="mikrotik_port" 
                                           name="mikrotik_port" 
                                           value="{{ old('mikrotik_port', $tenant->mikrotik_port ?? 8728) }}"
                                           placeholder="8728"
                                           class="w-full rounded-xl border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500 font-mono">
                                    <p class="text-xs text-gray-400 mt-1">Standard RouterOS API port is <strong>8728</strong> (or <strong>8729</strong> for SSL/TLS).</p>
                                    @error('mikrotik_port')
                                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                    <!-- Username -->
                                    <div>
                                        <label for="mikrotik_username" class="block font-semibold text-gray-800 mb-1.5">
                                            API Username
                                        </label>
                                        <input type="text" 
                                               id="mikrotik_username" 
                                               name="mikrotik_username" 
                                               value="{{ old('mikrotik_username', $tenant->mikrotik_username) }}"
                                               placeholder="e.g. billing_api"
                                               class="w-full rounded-xl border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500 font-mono">
                                        @error('mikrotik_username')
                                            <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <!-- Password -->
                                    <div>
                                        <label for="mikrotik_password" class="block font-semibold text-gray-800 mb-1.5">
                                            API Password
                                        </label>
                                        <input type="password" 
                                               id="mikrotik_password" 
                                               name="mikrotik_password" 
                                               placeholder="{{ $tenant->mikrotik_password ? '•••••••• (Encrypted in DB)' : 'Enter router API password' }}"
                                               class="w-full rounded-xl border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500 font-mono">
                                        <p class="text-xs text-gray-400 mt-1">
                                            {{ $tenant->mikrotik_password ? 'Leave blank to preserve existing password.' : 'Stored safely using AES-256 encryption.' }}
                                        </p>
                                        @error('mikrotik_password')
                                            <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                                        @enderror
                                    </div>
                                </div>

                            </div>

                            <!-- Form Actions -->
                            <div class="mt-8 pt-6 border-t border-gray-100 flex flex-wrap items-center justify-between gap-3">
                                <div class="flex items-center gap-2">
                                    <button type="button" 
                                            @click="runTest()" 
                                            :disabled="testingConnection || !'{{ $tenant->mikrotik_host }}'"
                                            class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-gray-300 text-gray-700 bg-white hover:bg-gray-50 text-xs font-semibold shadow-sm transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                                        <svg x-show="testingConnection" class="animate-spin h-3.5 w-3.5 text-gray-500" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        <svg x-show="!testingConnection" class="w-3.5 h-3.5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                        </svg>
                                        <span x-text="testingConnection ? 'Testing API Connection...' : 'Test Connection'"></span>
                                    </button>

                                    @if($tenant->mikrotik_host)
                                        <button type="button" 
                                                @click="window.confirmAction('Disconnect MikroTik router and clear saved credentials for {{ addslashes($tenant->name) }}?').then(confirmed => { if (confirmed) document.getElementById('disconnectRouterForm').requestSubmit(); })"
                                                class="inline-flex items-center gap-1.5 px-3 py-2.5 rounded-xl border border-red-200 text-red-600 hover:bg-red-50 text-xs font-semibold transition-colors">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                            <span>Disconnect</span>
                                        </button>
                                    @endif
                                </div>

                                <button type="submit" 
                                        class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-xl text-xs font-semibold shadow-sm shadow-blue-500/20 transition-colors">
                                    Save Router Settings
                                </button>
                            </div>
                        </form>

                        @if($tenant->mikrotik_host)
                            <form id="disconnectRouterForm" method="POST" action="{{ route('operator.settings.router.disconnect') }}" class="hidden">
                                @csrf
                            </form>
                        @endif

                        <!-- Live Test Results Banner -->
                        <div x-show="testResult" class="mt-6" x-cloak>
                            <div :class="testResult === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-red-50 border-red-200 text-red-800'"
                                 class="p-4 rounded-xl border text-xs flex items-start gap-3">
                                <template x-if="testResult === 'success'">
                                    <svg class="w-4 h-4 text-emerald-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                </template>
                                <template x-if="testResult === 'error'">
                                    <svg class="w-4 h-4 text-red-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                </template>
                                <div>
                                    <strong x-text="testResult === 'success' ? 'Success' : 'Connection Error'"></strong>
                                    <p class="mt-0.5" x-text="testMessage"></p>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Right Sidebar: Setup Instructions & Help (1 col) -->
                <div class="space-y-6">
                    
                    <!-- Router Quick Actions Card -->
                    <div class="bg-white rounded-2xl border border-gray-200/80 p-6 shadow-sm space-y-4">
                        <div class="flex items-center gap-2 text-gray-900 font-bold text-sm">
                            <div class="w-7 h-7 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold text-xs">
                                ⚡
                            </div>
                            <h3>Network Quick Actions</h3>
                        </div>

                        <p class="text-xs text-gray-500">
                            Perform immediate maintenance operations across routers assigned to <strong>{{ $tenant->name ?? 'this workspace' }}</strong>.
                        </p>

                        <div class="space-y-2 pt-1">
                            <form method="POST" action="{{ route('operator.settings.router.sync-all') }}">
                                @csrf
                                <button type="submit" 
                                        class="w-full flex items-center justify-between px-3.5 py-2.5 rounded-xl bg-blue-50/70 hover:bg-blue-100/70 text-blue-700 text-xs font-semibold border border-blue-200/60 transition-colors">
                                    <span class="flex items-center gap-2">
                                        <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                        Sync Profiles to All Routers
                                    </span>
                                    <span>&rarr;</span>
                                </button>
                            </form>

                            <a href="{{ route('operator.routers.index') }}" 
                               class="w-full flex items-center justify-between px-3.5 py-2.5 rounded-xl bg-gray-50 hover:bg-gray-100 text-gray-700 text-xs font-semibold border border-gray-200/80 transition-colors">
                                <span class="flex items-center gap-2">
                                    <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/></svg>
                                    View MikroTik Routers
                                </span>
                                <span>&rarr;</span>
                            </a>

                            <a href="{{ route('operator.routers.create') }}" 
                               class="w-full flex items-center justify-between px-3.5 py-2.5 rounded-xl bg-gray-50 hover:bg-gray-100 text-gray-700 text-xs font-semibold border border-gray-200/80 transition-colors">
                                <span class="flex items-center gap-2">
                                    <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                    Provision New Router
                                </span>
                                <span>&rarr;</span>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Quick Setup Guide Card -->
                    <div class="bg-white rounded-2xl border border-gray-200/80 p-6 shadow-sm">
                        <div class="flex items-center gap-2 mb-4 text-gray-900 font-bold text-sm">
                            <div class="w-7 h-7 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center font-bold text-xs">
                                ⚙️
                            </div>
                            <h3>RouterOS Quick Setup</h3>
                        </div>

                        <p class="text-xs text-gray-500 mb-4">
                            Run these commands in your MikroTik RouterOS Terminal (WinBox / SSH) to enable API access:
                        </p>

                        <!-- Terminal Command 1 -->
                        <div class="mb-4">
                            <span class="text-[11px] font-semibold text-gray-700 block mb-1">1. Enable API Service</span>
                            <div class="bg-slate-900 text-slate-100 p-3 rounded-xl font-mono text-[11px] overflow-x-auto select-all">
                                /ip service enable api
                            </div>
                        </div>

                        <!-- Terminal Command 2 -->
                        <div class="mb-4">
                            <span class="text-[11px] font-semibold text-gray-700 block mb-1">2. Create Dedicated API User</span>
                            <div class="bg-slate-900 text-slate-100 p-3 rounded-xl font-mono text-[11px] overflow-x-auto select-all">
                                /user add name=billing_api group=full password=YourStrongPassword
                            </div>
                        </div>

                        <!-- Terminal Command 3 -->
                        <div>
                            <span class="text-[11px] font-semibold text-gray-700 block mb-1">3. Restrict Access (Optional)</span>
                            <div class="bg-slate-900 text-slate-100 p-3 rounded-xl font-mono text-[11px] overflow-x-auto select-all">
                                /ip service set api address={{ request()->server('SERVER_ADDR', 'YOUR_SERVER_IP') }}
                            </div>
                        </div>
                    </div>

                    <!-- Security & Architecture Card -->
                    <div class="bg-blue-50/70 border border-blue-200/80 rounded-2xl p-5">
                        <h4 class="text-xs font-bold text-blue-900 mb-2 flex items-center gap-1.5">
                            <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                            Enterprise Multi-Tenant Security
                        </h4>
                        <p class="text-xs text-blue-800/80 leading-relaxed">
                            Your router credentials are stored in your isolated database record and encrypted using Laravel's AES-256-CBC cipher. No shared configuration is used across operators.
                        </p>
                    </div>

                </div>

            </div>

        </div>
    </div>
</x-app-layout>
