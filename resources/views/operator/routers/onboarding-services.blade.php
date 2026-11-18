<x-app-layout>
    <div class="min-h-screen bg-gray-950 px-4 py-8 text-white sm:px-6 lg:px-8"
         x-data="{ enableHotspot: @js((bool) old('enable_hotspot', $router->enable_hotspot)) }">
        <div class="mx-auto max-w-5xl">
            <a href="{{ route('operator.routers.index') }}" class="text-sm text-gray-400 hover:text-white">Mikro Tiks</a>
            <h1 class="mt-3 text-2xl font-bold">Add MikroTik Device</h1>
            <p class="mt-2 text-sm text-gray-400">The secure tunnel is connected. Review the router access and configure its services.</p>

            <div class="mt-8 grid grid-cols-3 overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
                <div class="border-b border-gray-800 p-4">
                    <span class="text-sm font-semibold text-gray-400">✓ &nbsp; Connection</span>
                    <p class="mt-1 text-xs text-gray-500">Basic device information</p>
                </div>
                <div class="border-b border-gray-800 p-4">
                    <span class="text-sm font-semibold text-gray-400">✓ &nbsp; Device Details</span>
                    <p class="mt-1 text-xs text-gray-500">Provisioning command</p>
                </div>
                <div class="border-b-2 border-orange-500 p-4">
                    <span class="text-sm font-semibold text-orange-400">03 &nbsp; Service Setup</span>
                    <p class="mt-1 text-xs text-gray-400">Configure HotSpot</p>
                </div>
            </div>

            @if ($errors->any())
                <div class="mt-5 rounded-lg border border-red-800 bg-red-950 p-4 text-sm text-red-200" role="alert">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('operator.routers.onboarding.services.configure', ['tenant' => $tenant->slug, 'router' => $router->id]) }}" class="mt-6 space-y-5">
                @csrf
                <input type="hidden" name="_idempotency_key" value="{{ \Illuminate\Support\Str::random(40) }}">
                <input type="hidden" name="enable_hotspot" :value="enableHotspot ? 1 : 0">

                <section class="rounded-xl border border-gray-800 bg-gray-900">
                    <div class="border-b border-gray-800 p-5">
                        <h2 class="font-semibold">Ethernet Ports</h2>
                        <p class="mt-1 text-xs text-gray-400">Select client ports for the HotSpot bridge. WAN ({{ $wanInterface ?? 'ether1' }}) is excluded.</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3 p-5 sm:grid-cols-4">
                        @forelse ($availablePorts as $port)
                            <label class="flex items-center gap-2 rounded-lg border border-gray-700 p-3 text-sm">
                                <input type="checkbox" name="ethernet_ports[]" value="{{ $port }}"
                                       @checked(in_array($port, old('ethernet_ports', $router->ethernet_ports ?? $availablePorts->all()), true))>
                                <span class="font-mono">{{ $port }}</span>
                            </label>
                        @empty
                            <p class="col-span-full text-sm text-amber-200">No supported Ethernet client ports were detected. HotSpot setup is unavailable until a router interface can be identified.</p>
                        @endforelse
                    </div>
                    <p class="px-5 pb-5 text-xs text-gray-500">
                        Detected interfaces: {{ collect($interfaces)->pluck('name')->filter()->implode(', ') ?: 'No interface names returned' }}
                    </p>
                </section>

                <section class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                    <h2 class="font-semibold">Router Access</h2>
                    <p class="mt-1 text-xs text-gray-400">Remote management is restricted to the encrypted WireGuard tunnel.</p>
                    <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div><dt class="text-xs text-gray-500">Connection Mode</dt><dd class="mt-1 text-sm">WireGuard VPN</dd></div>
                        <div><dt class="text-xs text-gray-500">WireGuard Tunnel IP</dt><dd class="mt-1 font-mono text-sm">{{ $router->vpnPeer?->tunnel_ip ?? 'Not assigned' }}</dd></div>
                        <div><dt class="text-xs text-gray-500">API Username</dt><dd class="mt-1 font-mono text-sm">{{ $router->api_username }}</dd></div>
                        <div><dt class="text-xs text-gray-500">API Password</dt><dd class="mt-1 text-sm">Configured and stored securely</dd></div>
                    </dl>
                </section>

                <section class="rounded-xl border border-gray-800 bg-gray-900">
                    <div class="border-b border-gray-800 p-5">
                        <h2 class="font-semibold">HotSpot Configuration</h2>
                    </div>
                    <div class="space-y-5 p-5">
                        <label class="flex items-start gap-3 rounded-lg border border-gray-700 p-4">
                            <input type="checkbox" x-model="enableHotspot" class="mt-1">
                            <span><span class="block text-sm font-medium">Enable HotSpot</span><span class="mt-1 block text-xs text-gray-400">Configure HotSpot service on this router.</span></span>
                        </label>
                        <div x-show="enableHotspot" x-cloak class="grid gap-5 sm:grid-cols-2">
                            <div>
                                <label for="hotspot_network" class="mb-1 block text-sm font-medium">HotSpot Network</label>
                                <input id="hotspot_network" name="hotspot_network" required x-bind:disabled="!enableHotspot"
                                       value="{{ old('hotspot_network', $router->hotspot_config['network'] ?? config('services.mikrotik.hotspot_network')) }}"
                                       class="w-full rounded-lg border-gray-700 bg-gray-950 font-mono text-white">
                            </div>
                            <div>
                                <label for="gateway_ip" class="mb-1 block text-sm font-medium">Gateway IP</label>
                                <input id="gateway_ip" name="gateway_ip" required x-bind:disabled="!enableHotspot"
                                       value="{{ old('gateway_ip', $router->hotspot_config['gateway'] ?? config('services.mikrotik.gateway')) }}"
                                       class="w-full rounded-lg border-gray-700 bg-gray-950 font-mono text-white">
                            </div>
                            <div>
                                <label for="dns_servers" class="mb-1 block text-sm font-medium">DNS Servers</label>
                                <input id="dns_servers" name="dns_servers" required x-bind:disabled="!enableHotspot"
                                       value="{{ old('dns_servers', $router->hotspot_config['dns'] ?? implode(',', (array) config('services.mikrotik.dns_servers')) ) }}"
                                       class="w-full rounded-lg border-gray-700 bg-gray-950 font-mono text-white">
                            </div>
                            <div>
                                <label for="hotspot_domain" class="mb-1 block text-sm font-medium">HotSpot Login URL / Domain</label>
                                <div class="flex items-center">
                                    <span class="rounded-l-lg border border-r-0 border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-400">http://</span>
                                    <input id="hotspot_domain" name="hotspot_domain" required x-bind:disabled="!enableHotspot"
                                           value="{{ old('hotspot_domain', $router->hotspot_config['domain'] ?? config('services.mikrotik.hotspot_domain')) }}"
                                           class="min-w-0 flex-1 rounded-r-lg border-gray-700 bg-gray-950 font-mono text-white">
                                </div>
                                <p class="mt-1 text-xs text-gray-500">Use the HotSpot DNS name configured on the router.</p>
                            </div>
                        </div>
                    </div>
                </section>

                <label class="flex items-start gap-3 rounded-xl border border-amber-800 bg-amber-950 p-4 text-sm text-amber-100">
                    <input type="checkbox" name="confirm_dedicated_router" value="1" required class="mt-1">
                    <span>I confirm this is a fresh, dedicated router, I have an out-of-band recovery path, and I understand service setup changes the selected client interfaces and HotSpot configuration.</span>
                </label>

                @if ($router->hardware_model === 'CHR')
                    <p class="rounded-lg border border-gray-700 bg-gray-900 p-4 text-sm text-gray-300">
                        PPPoE is intentionally not configured in the CHR HotSpot lab. It will require a separately selected interface or VLAN and separate subscriber accounts.
                    </p>
                @else
                    <p class="rounded-lg border border-gray-700 bg-gray-900 p-4 text-sm text-gray-300">
                        PPPoE subscriber setup is not yet available. This step currently configures HotSpot only.
                    </p>
                @endif

                <div class="flex justify-between">
                    <a href="{{ route('operator.routers.onboarding', ['tenant' => $tenant->slug, 'router' => $router->id]) }}"
                       class="rounded-lg border border-gray-700 px-4 py-2 text-sm text-gray-300">Back</a>
                    <button type="submit" class="rounded-lg bg-orange-600 px-6 py-3 text-sm font-semibold hover:bg-orange-500">
                        Create and configure router
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
