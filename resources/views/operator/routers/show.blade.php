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
             x-data="{ testing: false, testResult: null, requestingTelemetry: false, telemetryResult: null }">
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
                            {{ in_array($healthStatus, ['agent confirmed', 'online'], true) ? 'bg-green-100 text-green-800' : (in_array($healthStatus, ['last known', 'provisioning'], true) ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-800') }}">
                            <span class="h-1.5 w-1.5 rounded-full
                                {{ in_array($healthStatus, ['agent confirmed', 'online'], true) ? 'bg-green-600' : (in_array($healthStatus, ['last known', 'provisioning'], true) ? 'bg-amber-600' : 'bg-red-600') }}"></span>
                            {{ ucfirst($healthStatus) }}
                        </span>
                    </div>
                    <p class="text-sm text-gray-500">
                        {{ $router->management_ip ?: 'No management host configured' }}
                        <span aria-hidden="true">·</span>
                        {{ $router->hardware_model ?: 'Unknown model' }}
                    </p>
                </div>
                <div class="flex gap-2">
                    <a href="{{ route('operator.routers.edit', ['tenant' => $tenant->slug, 'router' => $router->id]) }}"
                       class="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700">
                        Edit router
                    </a>
                    <button type="button" onclick="window.location.reload()"
                            class="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        Refresh
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="flex min-h-36 flex-col justify-between rounded-xl bg-blue-600 p-5 text-white shadow-sm">
                    <p class="text-sm font-medium text-blue-100">CPU Load</p>
                    <div>
                        <p class="mt-2 text-3xl font-bold">{{ $liveData['ok'] ? $liveData['cpu_load'].'%' : '—' }}</p>
                        <p class="mt-1 text-xs text-blue-100">{{ $liveData['ok'] ? 'Last reported load' : 'Live data unavailable' }}</p>
                    </div>
                </div>
                <div class="flex min-h-36 flex-col justify-between rounded-xl bg-orange-500 p-5 text-white shadow-sm">
                    <p class="text-sm font-medium text-orange-100">Memory Usage</p>
                    <div>
                        <p class="mt-2 text-3xl font-bold">{{ $liveData['ok'] ? $liveData['memory_usage'].'%' : '—' }}</p>
                        <p class="mt-1 text-xs text-orange-100">{{ $liveData['ok'] ? 'Last reported usage' : 'Live data unavailable' }}</p>
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
                        @if(config('site_agent.required'))
                            <div class="flex justify-between gap-4 py-3">
                                <dt class="text-sm text-gray-500">Site agent last reported</dt>
                                <dd class="text-right text-sm font-medium text-gray-900">{{ $agentLastReportedAt?->diffForHumans() ?? 'Never' }}</dd>
                            </div>
                            <div class="flex justify-between gap-4 py-3">
                                <dt class="text-sm text-gray-500">Router telemetry last reported</dt>
                                <dd class="text-right text-sm font-medium text-gray-900">{{ $router->telemetry_at?->diffForHumans() ?? 'Never' }}</dd>
                            </div>
                        @endif
                    </dl>
                </section>

                <section class="flex flex-col justify-between rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                    <div>
                        <h2 class="text-base font-bold text-gray-900">Connection Status</h2>
                        <p class="mb-4 mt-2 text-sm text-gray-500">
                            Verify saved RouterOS API credentials after importing the setup script. In production, the paired site agent runs this check on the router's local network.
                        </p>
                        @if($latestConnectionTest)
                            <p class="mb-3 text-xs text-gray-600">
                                Latest agent test:
                                <span class="font-semibold">{{ ucfirst($latestConnectionTest->status) }}</span>
                                @if($latestConnectionTest->failure_reason)
                                    — {{ $latestConnectionTest->failure_reason }}
                                @endif
                            </p>
                        @endif
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
                        <span x-text="testing ? 'Testing...' : 'Test Router API'">Test Router API</span>
                    </button>
                    @if(config('site_agent.required'))
                        <div x-cloak x-show="telemetryResult" role="status" aria-live="polite"
                             class="mt-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800"
                             x-text="telemetryResult ? telemetryResult.message : ''"></div>
                        <button type="button"
                                @click="
                                    requestingTelemetry = true;
                                    telemetryResult = null;
                                    window.apiFetch('{{ route('operator.routers.telemetry', ['tenant' => $tenant->slug, 'router' => $router->id]) }}', {
                                        method: 'POST',
                                    })
                                    .then(data => telemetryResult = data)
                                    .catch(error => telemetryResult = { message: error.message || 'Telemetry could not be requested.' })
                                    .finally(() => requestingTelemetry = false);
                                "
                                :disabled="requestingTelemetry"
                                class="mt-3 inline-flex w-full items-center justify-center rounded-lg border border-blue-600 px-4 py-2 text-sm font-medium text-blue-700 hover:bg-blue-50 disabled:cursor-wait disabled:opacity-60">
                            <span x-text="requestingTelemetry ? 'Requesting...' : 'Request telemetry from site agent'">Request telemetry from site agent</span>
                        </button>
                    @endif
                </section>

                <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 class="text-base font-bold text-gray-900">WireGuard management tunnel</h2>
                    <p class="mt-1 text-sm text-gray-500">
                        The tunnel is for encrypted management traffic only; it does not carry customer hotspot traffic.
                    </p>
                    @if($router->vpnPeer)
                        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-gray-900">
                                    {{ $router->vpnPeer->enabled ? 'Peer enabled' : 'Peer disabled' }}
                                    <span class="font-mono font-normal text-gray-600">({{ $router->vpnPeer->tunnel_ip }})</span>
                                </p>
                                <p class="mt-1 text-xs text-gray-500">
                                    Last handshake: {{ $router->vpnPeer->last_handshake_at?->diffForHumans() ?? 'No handshake recorded yet' }}
                                </p>
                            </div>
                            @if(!$router->vpnPeer->config_downloaded_at)
                                <a href="{{ route('operator.routers.vpn-peer.download', ['tenant' => $tenant->slug, 'router' => $router->id]) }}"
                                   class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700">
                                    Download one-time router config
                                </a>
                            @else
                                <span class="text-xs text-gray-500">Router config already downloaded. Contact platform support to rotate this peer.</span>
                            @endif
                        </div>
                    @else
                        <form class="mt-4" method="POST" action="{{ route('operator.routers.vpn-peer.store', ['tenant' => $tenant->slug, 'router' => $router->id]) }}">
                            @csrf
                            <button type="submit" class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700">
                                Generate WireGuard peer
                            </button>
                        </form>
                    @endif
                    @error('wireguard')
                        <p class="mt-3 text-sm text-red-700">{{ $message }}</p>
                    @enderror
                    <p class="mt-3 text-xs text-amber-700">
                        The configuration contains the router's private key and is available once. Store it securely and do not share or log it.
                    </p>
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
                        <button type="button" @click="deployNow()" :disabled="deploying || !{{ $hasApiCredentials ? 'true' : 'false' }}"
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
                @unless ($hasApiCredentials)
                    <p class="mt-2 text-xs text-amber-700">Save a router host, API username, and API password before deploying via API.</p>
                @endunless

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

                <div class="mt-4 border-t border-gray-100 pt-3">
                    <p class="text-[11px] text-gray-600">
                        The downloaded .rsc contains the router API password. Treat it as a secret, import it only on the intended fresh/dedicated router, and do not paste it into tickets or logs.
                    </p>
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
