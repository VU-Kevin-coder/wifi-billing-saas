<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-2 text-sm text-gray-500">
            <a href="{{ route('operator.routers.index', ['tenant' => $tenant->slug]) }}" class="hover:text-gray-900">MikroTik Routers</a>
            <span aria-hidden="true">&rsaquo;</span>
            <span class="font-medium text-gray-900">{{ $router->name }}</span>
        </div>
    </x-slot>

    <div class="min-h-screen bg-gray-50 py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8"
             x-data="{ testing: false, testResult: null }">
            @if(session('status'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800" role="status">
                    {{ session('status') }}
                </div>
            @endif

            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                <div>
                    <div class="mb-1 flex flex-wrap items-center gap-3">
                        <h1 class="text-2xl font-bold text-gray-900">{{ $router->name }}</h1>
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold
                            {{ $router->status === 'online' ? 'bg-green-100 text-green-800' : ($router->status === 'provisioning' ? 'bg-blue-100 text-blue-800' : 'bg-red-100 text-red-800') }}">
                            <span class="h-1.5 w-1.5 rounded-full
                                {{ $router->status === 'online' ? 'bg-green-600' : ($router->status === 'provisioning' ? 'bg-blue-600' : 'bg-red-600') }}"></span>
                            {{ ucfirst($router->status) }}
                        </span>
                    </div>
                    <p class="text-sm text-gray-500">
                        {{ $router->management_ip ?: 'No management host configured' }}
                        <span aria-hidden="true">·</span>
                        {{ $router->hardware_model ?: 'Unknown model' }}
                    </p>
                </div>
                <button type="button" onclick="window.location.reload()"
                        class="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                    Refresh
                </button>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="flex min-h-36 flex-col justify-between rounded-xl bg-blue-600 p-5 text-white shadow-sm">
                    <p class="text-sm font-medium text-blue-100">CPU Load</p>
                    <div>
                        <p class="mt-2 text-3xl font-bold">{{ $liveData['cpu_load'] }}%</p>
                        <p class="mt-1 text-xs text-blue-100">{{ $liveData['ok'] ? 'Live system load' : 'Live data unavailable' }}</p>
                    </div>
                </div>
                <div class="flex min-h-36 flex-col justify-between rounded-xl bg-orange-500 p-5 text-white shadow-sm">
                    <p class="text-sm font-medium text-orange-100">Memory Usage</p>
                    <div>
                        <p class="mt-2 text-3xl font-bold">{{ $liveData['memory_usage'] }}%</p>
                        <p class="mt-1 text-xs text-orange-100">{{ $liveData['ok'] ? 'Live memory usage' : 'Live data unavailable' }}</p>
                    </div>
                </div>
                <div class="flex min-h-36 flex-col justify-between rounded-xl bg-green-600 p-5 text-white shadow-sm">
                    <p class="text-sm font-medium text-green-100">Uptime</p>
                    <div>
                        <p class="mt-2 break-all text-2xl font-bold">{{ $liveData['uptime'] }}</p>
                        <p class="mt-1 text-xs text-green-100">Time since last reboot</p>
                    </div>
                </div>
                <div class="flex min-h-36 flex-col justify-between rounded-xl bg-indigo-600 p-5 text-white shadow-sm">
                    <p class="text-sm font-medium text-indigo-100">Active Users</p>
                    <div>
                        <p class="mt-2 text-3xl font-bold">{{ $liveData['active_users'] ?? '—' }}</p>
                        <p class="mt-1 text-xs text-indigo-100">
                            {{ is_null($liveData['active_users']) ? 'Session count unavailable' : 'Connected hotspot clients' }}
                        </p>
                    </div>
                </div>
            </div>

            @if(!$liveData['ok'])
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800" role="status">
                    {{ $liveData['message'] }}
                </div>
            @endif

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 class="mb-4 text-base font-bold text-gray-900">Router Details</h2>
                    <dl class="divide-y divide-gray-100">
                        <div class="flex justify-between gap-4 py-3">
                            <dt class="text-sm text-gray-500">Management IP / Host</dt>
                            <dd class="break-all text-right font-mono text-sm font-medium text-gray-900">{{ $router->management_ip ?: '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4 py-3">
                            <dt class="text-sm text-gray-500">API Port</dt>
                            <dd class="text-sm font-medium text-gray-900">{{ $router->api_port ?: 8728 }}</dd>
                        </div>
                        <div class="flex justify-between gap-4 py-3">
                            <dt class="text-sm text-gray-500">API Username</dt>
                            <dd class="break-all text-right text-sm font-medium text-gray-900">{{ $router->api_username ?: '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4 py-3">
                            <dt class="text-sm text-gray-500">Site</dt>
                            <dd class="text-right text-sm font-medium text-gray-900">{{ $assignedSite?->name ?? $router->getRawOriginal('site') ?? 'Unassigned' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4 py-3">
                            <dt class="text-sm text-gray-500">Last Seen</dt>
                            <dd class="text-right text-sm font-medium text-gray-900">{{ $router->last_seen_at?->diffForHumans() ?? 'Never' }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="flex flex-col justify-between rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                    <div>
                        <h2 class="text-base font-bold text-gray-900">Connection Status</h2>
                        <p class="mb-4 mt-2 text-sm text-gray-500">
                            Verify that this application can reach the router's read-only RouterOS API.
                        </p>
                        <div x-cloak x-show="testResult" role="status" aria-live="polite"
                             :class="testResult && testResult.ok ? 'border-green-200 bg-green-50 text-green-800' : 'border-red-200 bg-red-50 text-red-800'"
                             class="mb-4 rounded-lg border p-3 text-sm"
                             x-text="testResult ? testResult.message : ''"></div>
                    </div>
                    <button type="button"
                            @click="
                                testing = true;
                                testResult = null;
                                window.apiFetch('{{ route('operator.routers.test', ['tenant' => $tenant->slug, 'router' => $router->id]) }}', {
                                    method: 'POST',
                                })
                                .then(data => {
                                    testResult = data;
                                })
                                .catch(error => testResult = { ok: false, message: error.message || 'The connection test could not be completed.' })
                                .finally(() => testing = false);
                            "
                            :disabled="testing"
                            class="inline-flex w-full items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 disabled:cursor-wait disabled:opacity-60">
                        <span x-text="testing ? 'Testing...' : 'Test LAN API'">Test LAN API</span>
                    </button>
                </section>

                <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 class="text-base font-bold text-gray-900">Hotspot Gateway</h2>
                    <p class="mb-4 mt-1 text-sm text-gray-500">
                        The address customers are sent to after paying or redeeming a voucher to log in to this router's hotspot.
                        It must match the hotspot address configured on the router.
                    </p>
                    <form method="POST" action="{{ route('operator.routers.gateway', ['tenant' => $tenant->slug, 'router' => $router->id]) }}">
                        @csrf
                        @method('PATCH')
                        <label for="gateway_ip" class="mb-1 block text-sm font-medium text-gray-700">Gateway IP</label>
                        <input id="gateway_ip" name="gateway_ip" type="text" inputmode="decimal" maxlength="15" required
                               value="{{ old('gateway_ip', $router->hotspot_config['gateway'] ?? '') }}"
                               placeholder="e.g. 10.5.50.1"
                               class="mb-2 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('gateway_ip') <p class="mb-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        <button type="submit"
                                class="inline-flex w-full items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700">
                            Save gateway
                        </button>
                    </form>
                </section>
            </div>

            <!-- Router Provisioning & Setup -->
            <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm" x-data="{
                deploying: false,
                deployResult: null,
                showScript: false,
                async deployNow() {
                    this.deploying = true;
                    this.deployResult = null;
                    try {
                        const data = await window.apiFetch('{{ route('operator.routers.provision', ['tenant' => $tenant->slug, 'router' => $router->id]) }}', { method: 'POST' });
                        this.deployResult = data;
                        if (data.ok) {
                            setTimeout(() => window.location.reload(), 1500);
                        }
                    } catch (error) {
                        this.deployResult = { ok: false, message: error.message || 'Network error communicating with router API.' };
                    } finally {
                        this.deploying = false;
                    }
                }
            }">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between pb-4 border-b border-gray-100 gap-3">
                    <div>
                        <h2 class="text-base font-bold text-gray-900 flex items-center gap-2">
                            <span>RouterOS Provisioning & Setup</span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $router->status === 'online' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' }}">
                                {{ $router->status === 'online' ? 'Provisioned' : 'Setup Required' }}
                            </span>
                        </h2>
                        <p class="mt-1 text-xs text-gray-500">
                            Apply hotspot configuration, captive portal redirection, walled garden, and package profiles to this MikroTik router.
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="{{ route('operator.routers.provision-script', ['tenant' => $tenant->slug, 'router' => $router->id, 'download' => 1]) }}"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-xs font-semibold text-gray-700 hover:bg-gray-50 shadow-sm transition-colors">
                            <svg class="w-3.5 h-3.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            Download .rsc
                        </a>
                        <button type="button" @click="deployNow()" :disabled="deploying || !'{{ $router->management_ip }}'"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-orange-600 text-xs font-semibold text-white hover:bg-orange-700 shadow-sm transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                            <svg x-show="deploying" class="animate-spin h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span x-text="deploying ? 'Deploying...' : 'Deploy via API'"></span>
                        </button>
                    </div>
                </div>

                <div x-show="deployResult" class="mt-4" x-cloak>
                    <div :class="deployResult && deployResult.ok ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-red-50 border-red-200 text-red-800'"
                         class="p-3.5 rounded-xl border text-xs flex items-center justify-between">
                        <span x-text="deployResult ? deployResult.message : ''"></span>
                        <button type="button" @click="deployResult = null" class="text-xs font-bold">&times;</button>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-4 text-xs">
                    <div class="bg-gray-50 p-3 rounded-lg border border-gray-100">
                        <span class="text-gray-400 block mb-0.5">Hotspot Network</span>
                        <span class="font-mono font-semibold text-gray-800">{{ $router->hotspot_config['network'] ?? '10.10.0.0/16' }}</span>
                    </div>
                    <div class="bg-gray-50 p-3 rounded-lg border border-gray-100">
                        <span class="text-gray-400 block mb-0.5">Gateway IP</span>
                        <span class="font-mono font-semibold text-gray-800">{{ $router->hotspot_config['gateway'] ?? '10.10.0.1' }}</span>
                    </div>
                    <div class="bg-gray-50 p-3 rounded-lg border border-gray-100">
                        <span class="text-gray-400 block mb-0.5">Login Domain</span>
                        <span class="font-mono font-semibold text-gray-800">{{ $router->hotspot_config['domain'] ?? 'hotspot.local' }}</span>
                    </div>
                    <div class="bg-gray-50 p-3 rounded-lg border border-gray-100">
                        <span class="text-gray-400 block mb-0.5">Assigned Ports</span>
                        <span class="font-mono font-semibold text-gray-800">{{ implode(', ', $router->ethernet_ports ?? ['ether2', 'ether3']) }}</span>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-t border-gray-100">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-semibold text-gray-700">RouterOS Terminal Commands</span>
                        <div class="flex items-center gap-2">
                            <button type="button" @click="showScript = !showScript" class="text-xs text-blue-600 hover:underline font-medium">
                                <span x-text="showScript ? 'Hide Script' : 'View Full Script'"></span>
                            </button>
                            <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('rsc-script-content').innerText)"
                                    class="text-xs text-gray-600 hover:text-gray-900 border border-gray-300 rounded px-2 py-0.5 font-medium bg-gray-50 hover:bg-gray-100">
                                Copy Script
                            </button>
                        </div>
                    </div>
                    <p class="text-[11px] text-gray-500 mb-2">Paste these commands into your MikroTik Terminal (WinBox or SSH) to configure the hotspot and walled garden rules in seconds.</p>
                    <div x-show="showScript" x-cloak class="mt-2">
                        <pre id="rsc-script-content" class="bg-slate-900 text-slate-100 p-4 rounded-xl font-mono text-[11px] overflow-x-auto max-h-72 select-all whitespace-pre">{{ $provisionScript ?? '' }}</pre>
                    </div>
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
