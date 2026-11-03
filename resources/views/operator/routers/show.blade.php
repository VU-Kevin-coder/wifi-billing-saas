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
                                fetch('{{ route('operator.routers.test', ['tenant' => $tenant->slug, 'router' => $router->id]) }}', {
                                    method: 'POST',
                                    headers: {
                                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                                        'Accept': 'application/json'
                                    }
                                })
                                .then(async response => {
                                    const data = await response.json();
                                    if (!response.ok) throw new Error(data.message || 'The connection test failed.');
                                    testResult = data;
                                })
                                .catch(() => testResult = { ok: false, message: 'Network error: the connection test could not be completed.' })
                                .finally(() => testing = false);
                            "
                            :disabled="testing"
                            class="inline-flex w-full items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 disabled:cursor-wait disabled:opacity-60">
                        <span x-text="testing ? 'Testing...' : 'Test LAN API'">Test LAN API</span>
                    </button>
                </section>
            </div>
        </div>
    </div>
</x-app-layout>
