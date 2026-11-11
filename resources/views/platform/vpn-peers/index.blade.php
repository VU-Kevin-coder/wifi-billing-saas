<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">WireGuard router peers</h2>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        @if(session('status'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800" role="status">
                {{ session('status') }}
            </div>
        @endif
        @if($refreshError)
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" role="alert">
                Peer list loaded, but handshake refresh failed: {{ $refreshError }}
            </div>
        @endif
        @error('wireguard')
            <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">{{ $message }}</div>
        @enderror

        <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <h3 class="text-base font-bold text-gray-900">Generate a router peer</h3>
            <p class="mt-1 text-sm text-gray-500">
                Requires an already provisioned Linux WireGuard interface and an externally reachable UDP endpoint.
            </p>
            @unless(config('wireguard.enabled'))
                <p class="mt-3 text-sm text-amber-800">WireGuard peer management is disabled until the host interface and environment settings are configured.</p>
            @endunless
            <form method="POST" action="{{ route('platform.vpn-peers.store') }}" class="mt-4 grid gap-3 md:grid-cols-4">
                @csrf
                <select name="tenant_id" required class="rounded-lg border-gray-300 text-sm">
                    <option value="">Select tenant</option>
                    @foreach($tenants as $tenant)
                        <option value="{{ $tenant->id }}" @selected(old('tenant_id') == $tenant->id)>{{ $tenant->name }}</option>
                    @endforeach
                </select>
                <select name="router_id" required class="rounded-lg border-gray-300 text-sm">
                    <option value="">Select router</option>
                    @foreach($routers as $router)
                        <option value="{{ $router->id }}" @selected(old('router_id') == $router->id)>
                            {{ $router->tenant?->name }} — {{ $router->name }}
                        </option>
                    @endforeach
                </select>
                <input name="name" value="{{ old('name') }}" required maxlength="100" placeholder="Peer name"
                       class="rounded-lg border-gray-300 text-sm">
                <button type="submit" @disabled(!config('wireguard.enabled'))
                        class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">
                    Generate peer
                </button>
                @foreach(['tenant_id', 'router_id', 'name'] as $field)
                    @error($field)<p class="text-xs text-red-700 md:col-span-4">{{ $message }}</p>@enderror
                @endforeach
            </form>
        </section>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-6 py-4">
                <h3 class="text-base font-bold text-gray-900">Peer inventory</h3>
            </div>
            @if($peers->isEmpty())
                <p class="px-6 py-8 text-sm text-gray-500">No WireGuard peers have been generated.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 text-left text-sm">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                            <tr>
                                <th class="px-6 py-3">Tenant / router</th>
                                <th class="px-6 py-3">Tunnel IP</th>
                                <th class="px-6 py-3">Peer status</th>
                                <th class="px-6 py-3">Last handshake</th>
                                <th class="px-6 py-3">Configuration</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($peers as $peer)
                                <tr>
                                    <td class="px-6 py-4">
                                        <div class="font-medium text-gray-900">{{ $peer->tenant?->name }}</div>
                                        <div class="text-xs text-gray-500">{{ $peer->router?->name }} · {{ $peer->name }}</div>
                                    </td>
                                    <td class="px-6 py-4 font-mono">{{ $peer->tunnel_ip }}</td>
                                    <td class="px-6 py-4">{{ $peer->enabled ? 'Enabled' : 'Disabled' }}</td>
                                    <td class="px-6 py-4">{{ $peer->last_handshake_at?->diffForHumans() ?? 'Never' }}</td>
                                    <td class="px-6 py-4">
                                        @if(!$peer->config_downloaded_at && $peer->private_key)
                                            <a href="{{ route('platform.vpn-peers.download', $peer) }}"
                                               class="font-semibold text-blue-700 hover:text-blue-900">Download once</a>
                                        @else
                                            <span class="text-xs text-gray-500">Already downloaded; rotate via support if needed</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-app-layout>
