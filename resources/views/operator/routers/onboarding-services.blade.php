<x-app-layout>
    <div class="min-h-screen bg-gray-50 px-4 py-8 text-gray-900 sm:px-6 lg:px-8"
         x-data="{
             enableHotspot: @js((bool) old('enable_hotspot', $router->enable_hotspot)),
             enablePppoe: @js((bool) old('enable_pppoe', $router->enable_pppoe)),
             wanInterface: @js(old('wan_interface', $wanInterface)),
             managementInterface: @js(old('management_interface', $managementInterface)),
             gatewayIp: @js(old('gateway_ip', $router->hotspot_config['gateway'] ?? config('services.mikrotik.gateway'))),
             async copyWirelessTestScript() {
                 await navigator.clipboard.writeText(document.getElementById('wireless-test-script').innerText);
                 window.toast?.success('Temporary Wi-Fi test script copied.');
             }
         }">
        <div class="mx-auto max-w-5xl">
            <a href="{{ route('operator.routers.index') }}" class="text-sm text-gray-500 hover:text-gray-800">Mikro Tiks</a>
            <h1 class="mt-3 text-2xl font-bold text-gray-900">Add MikroTik Device</h1>
            <p class="mt-2 text-sm text-gray-600">
                @if ($canConfigure)
                    The secure tunnel is connected. Assign each physical interface role and review the exact service changes before applying them.
                @else
                    Review the service setup step. Configuration remains locked until the secure tunnel, API-SSL, and RADIUS checks pass.
                @endif
            </p>

            <div class="mt-8 grid grid-cols-3 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 p-4">
                    <span class="text-sm font-semibold text-gray-600">✓ &nbsp; Connection</span>
                    <p class="mt-1 text-xs text-gray-500">Basic device information</p>
                </div>
                <div class="border-b border-gray-200 p-4">
                    <span class="text-sm font-semibold text-gray-600">✓ &nbsp; Device Details</span>
                    <p class="mt-1 text-xs text-gray-500">Provisioning command</p>
                </div>
                <div class="border-b-2 border-orange-500 p-4">
                    <span class="text-sm font-semibold text-orange-700">03 &nbsp; Service Setup</span>
                    <p class="mt-1 text-xs text-gray-500">Configure HotSpot and PPPoE</p>
                </div>
            </div>

            @if (! $canConfigure)
                <section class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-950" role="status">
                    <h2 class="font-semibold">Service configuration is not ready yet</h2>
                    <p class="mt-2 text-sm">
                        Current onboarding status: {{ str_replace('_', ' ', $router->onboarding->status) }}.
                        The Next button opens this step, but router changes stay disabled until WireGuard, API-SSL, and RADIUS readiness are verified.
                    </p>
                    <a href="{{ route('operator.routers.onboarding', ['tenant' => $tenant->slug, 'router' => $router->id]) }}"
                       class="mt-4 inline-flex rounded-lg border border-amber-300 bg-white px-4 py-2 text-sm font-semibold text-amber-900 hover:bg-amber-100">
                        Back to connection checks
                    </a>
                </section>
                @if (session('status'))
                    <p class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800" role="status">
                        {{ session('status') }}
                    </p>
                @endif
                @if ($errors->any())
                    <p class="mt-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700" role="alert">
                        {{ $errors->first() }}
                    </p>
                @endif
                @if ($wirelessTestEnabled)
                    <section class="mt-5 rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                        <h2 class="font-semibold">Temporary open Wi-Fi broadcast test</h2>
                        <p class="mt-2 text-sm text-gray-600">
                            This local-lab-only option creates a Winbox script that broadcasts the SSID immediately.
                            It does not configure HotSpot, voucher authentication, DHCP, or the login portal. Use it only
                            on the isolated router you confirmed for testing; its existing radio bridge membership is preserved.
                        </p>
                        <form method="POST" action="{{ route('operator.routers.onboarding.wireless-test', ['tenant' => $tenant->slug, 'router' => $router->id]) }}" class="mt-4 space-y-4">
                            @csrf
                            <div>
                                <label for="test_wifi_ssid" class="mb-1 block text-sm font-medium">Test Wi-Fi name (SSID)</label>
                                <input id="test_wifi_ssid" name="wifi_ssid" maxlength="32" required
                                       value="{{ old('wifi_ssid', $router->wifi_ssid) }}"
                                       class="w-full rounded-lg border-gray-300 bg-white text-gray-900 shadow-sm">
                            </div>
                            <label class="flex items-start gap-3 text-sm text-gray-700">
                                <input type="checkbox" name="confirm_builtin_wireless" value="1" required class="mt-1 rounded border-gray-300">
                                <span>I confirm this MikroTik has built-in wireless interfaces.</span>
                            </label>
                            <label class="flex items-start gap-3 text-sm text-red-800">
                                <input type="checkbox" name="confirm_isolated_router" value="1" required class="mt-1 rounded border-red-300">
                                <span>I confirm this dedicated test router is isolated from public WAN and production clients. I understand this SSID is open and has no voucher login until HotSpot setup is completed.</span>
                            </label>
                            <button type="submit" class="rounded-lg bg-amber-700 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-800">
                                Save SSID and generate Winbox test script
                            </button>
                        </form>

                        @if ($wirelessTestScript)
                            <div class="mt-5">
                                <div class="flex items-center justify-between gap-3">
                                    <h3 class="text-sm font-semibold">Temporary script - run locally in Winbox</h3>
                                    <button type="button" @click="copyWirelessTestScript()" class="text-sm font-semibold text-orange-700 hover:text-orange-800">
                                        Copy script
                                    </button>
                                </div>
                                <pre id="wireless-test-script" class="mt-3 overflow-x-auto rounded-lg bg-gray-100 p-4 text-xs text-blue-900">{{ $wirelessTestScript }}</pre>
                            </div>
                        @endif
                    </section>
                @endif
                <section class="mt-5 rounded-xl border border-blue-200 bg-blue-50 p-5 text-sm text-blue-950">
                    <h2 class="font-semibold">Customer login addresses</h2>
                    <p class="mt-2">Voucher portal: <a class="break-all font-mono underline" href="{{ $voucherPortalUrl }}" target="_blank" rel="noopener">{{ $voucherPortalUrl }}</a></p>
                    <p class="mt-2">Router HotSpot login after service setup: <span class="font-mono">{{ $routerLoginUrl ?? 'Available after a valid HotSpot gateway is set' }}</span></p>
                    <p class="mt-2 text-xs text-blue-800">The open Wi-Fi test script only broadcasts the network. The router login URL will not work until the HotSpot service is configured.</p>
                </section>
            @else
                @if ($errors->any())
                    <div class="mt-5 rounded-lg border border-red-800 bg-red-950 p-4 text-sm text-red-200" role="alert">
                        {{ $errors->first() }}
                    </div>
                @endif

            <form method="POST" action="{{ route('operator.routers.onboarding.services.configure', ['tenant' => $tenant->slug, 'router' => $router->id]) }}" class="mt-6 space-y-5">
                @csrf
                <input type="hidden" name="_idempotency_key" value="{{ \Illuminate\Support\Str::random(40) }}">
                <input type="hidden" name="enable_hotspot" :value="enableHotspot ? 1 : 0">
                <input type="hidden" name="enable_pppoe" :value="enablePppoe ? 1 : 0">

                <section class="grid gap-5 rounded-xl border border-blue-200 bg-blue-50 p-5 sm:grid-cols-2">
                    <div>
                        <label for="wan_interface" class="mb-1 block text-sm font-medium">Internet/WAN interface</label>
                        <select id="wan_interface" name="wan_interface" x-model="wanInterface" required class="w-full rounded-lg border-gray-300 bg-white text-gray-900 shadow-sm">
                            <option value="">Select the upstream Internet port</option>
                            @foreach ($availablePorts as $port)
                                <option value="{{ $port }}" @selected(old('wan_interface', $wanInterface) === $port)>{{ $port }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="management_interface" class="mb-1 block text-sm font-medium">Recovery/management interface</label>
                        <select id="management_interface" name="management_interface" x-model="managementInterface" required class="w-full rounded-lg border-gray-300 bg-white text-gray-900 shadow-sm">
                            <option value="">Select the port reserved for Winbox/recovery</option>
                            @foreach ($availablePorts as $port)
                                <option value="{{ $port }}" @selected(old('management_interface', $managementInterface) === $port)>{{ $port }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-gray-600">This port will never be placed in the HotSpot bridge or PPPoE service. Keep it physically available for Winbox recovery.</p>
                    </div>
                </section>

                <section x-show="enableHotspot" x-cloak class="rounded-xl border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-200 p-5">
                        <h2 class="font-semibold">Ethernet Ports</h2>
                        <p class="mt-1 text-xs text-gray-600">Selected ports on the known RouterOS default LAN bridge are moved into the dedicated HotSpot bridge; unbridged client ports are added there. Opted-in built-in Wi-Fi is also configured and bridged to HotSpot. WAN and management ports are excluded by server-side checks.</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3 p-5 sm:grid-cols-4">
                        @forelse ($availablePorts as $port)
                            <label class="flex items-center gap-2 rounded-lg border border-gray-200 p-3 text-sm">
                                <input type="checkbox" name="ethernet_ports[]" value="{{ $port }}"
                                       x-bind:disabled="!enableHotspot || '{{ $port }}' === wanInterface || '{{ $port }}' === managementInterface"
                                       @checked(
                                           $port !== old('wan_interface', $wanInterface)
                                           && $port !== old('management_interface', $managementInterface)
                                           && in_array($port, old('ethernet_ports', $router->ethernet_ports ?? $availablePorts), true)
                                       )>
                                <span class="font-mono">{{ $port }}</span>
                            </label>
                        @empty
                            <p class="col-span-full text-sm text-amber-800">No supported Ethernet client ports were detected. HotSpot setup is unavailable until a router interface can be identified.</p>
                        @endforelse
                    </div>
                    @if($router->has_wireless)
                        <div class="px-5 pb-3">
                            <label for="wifi_ssid" class="mb-1 block text-xs font-medium text-gray-700">Built-in Wi-Fi SSID</label>
                            <input id="wifi_ssid" name="wifi_ssid" maxlength="32" required x-bind:disabled="!enableHotspot"
                                   value="{{ old('wifi_ssid', $router->wifi_ssid) }}"
                                   class="w-full rounded-lg border-gray-300 bg-white text-sm text-gray-900 shadow-sm">
                            <p class="mt-1 text-xs text-gray-600">Broadcasts as an open network after service configuration; voucher authentication is handled by the HotSpot portal.</p>
                        </div>
                    @else
                        <p class="px-5 pb-2 text-xs text-gray-600">No built-in Wi-Fi SSID is configured. Connect an external access point to a selected HotSpot client port if needed.</p>
                    @endif
                    <p class="px-5 pb-5 text-xs text-gray-500">
                        Detected interfaces: {{ collect($interfaces)->pluck('name')->filter()->implode(', ') ?: 'No interface names returned' }}
                    </p>
                </section>

                <section class="rounded-xl border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-200 p-5">
                        <h2 class="font-semibold">PPPoE Configuration</h2>
                        <p class="mt-1 text-xs text-gray-600">PPPoE subscribers use their own interface or VLAN and RADIUS credentials, separate from HotSpot vouchers.</p>
                    </div>
                    <div class="space-y-5 p-5">
                        <label class="flex items-start gap-3 rounded-lg border border-gray-200 p-4">
                            <input type="checkbox" x-model="enablePppoe" @disabled(str_contains(strtolower((string) $router->hardware_model), 'chr')) class="mt-1">
                            <span>
                                <span class="block text-sm font-medium">Enable PPPoE</span>
                                <span class="mt-1 block text-xs text-gray-500">Set up a separate PPPoE access interface for manually created subscribers.</span>
                            </span>
                        </label>
                        @if (str_contains(strtolower((string) $router->hardware_model), 'chr'))
                            <p class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">PPPoE is unavailable on the CHR HotSpot lab. Test it only on a separate physical router/interface.</p>
                        @else
                            <div x-show="enablePppoe" x-cloak class="grid gap-5 sm:grid-cols-2">
                                <div>
                                    <label for="pppoe_interface" class="mb-1 block text-sm font-medium">PPPoE parent interface</label>
                                    <select id="pppoe_interface" name="pppoe_interface" required x-bind:disabled="!enablePppoe"
                                            class="w-full rounded-lg border-gray-300 bg-white text-gray-900 shadow-sm">
                                        <option value="">Select a separate Ethernet port</option>
                                        @foreach ($availablePorts as $port)
                                            <option value="{{ $port }}" @selected(old('pppoe_interface', $router->pppoe_config['interface'] ?? '') === $port)>{{ $port }}</option>
                                        @endforeach
                                    </select>
                                    <p class="mt-1 text-xs text-gray-500">The server rejects any port also assigned to HotSpot or WAN.</p>
                                </div>
                                <div>
                                    <label for="pppoe_vlan_id" class="mb-1 block text-sm font-medium">VLAN ID (optional)</label>
                                    <input id="pppoe_vlan_id" name="pppoe_vlan_id" type="number" min="1" max="4094"
                                           value="{{ old('pppoe_vlan_id', $router->pppoe_config['vlan_id'] ?? '') }}"
                                           x-bind:disabled="!enablePppoe"
                                           class="w-full rounded-lg border-gray-300 bg-white text-gray-900 shadow-sm">
                                    <p class="mt-1 text-xs text-gray-500">When set, the PPPoE server runs on a dedicated VLAN interface on the selected port.</p>
                                </div>
                                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 sm:col-span-2">
                                    <p class="text-sm font-medium">Platform-assigned PPPoE addressing</p>
                                    <p class="mt-2 text-xs text-gray-600">Network: <span class="font-mono text-gray-900">{{ $pppoeNetwork }}</span></p>
                                    <p class="mt-1 text-xs text-gray-600">Local gateway: <span class="font-mono text-gray-900">{{ $pppoeGateway }}</span></p>
                                    <p class="mt-1 text-xs text-gray-600">DNS: <span class="font-mono text-gray-900">{{ $pppoeDnsServers }}</span></p>
                                </div>
                            </div>
                        @endif
                    </div>
                </section>

                <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <h2 class="font-semibold">Router Access</h2>
                    <p class="mt-1 text-xs text-gray-600">Remote management is restricted to the encrypted WireGuard tunnel.</p>
                    <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div><dt class="text-xs text-gray-500">Connection Mode</dt><dd class="mt-1 text-sm text-gray-900">WireGuard VPN</dd></div>
                        <div><dt class="text-xs text-gray-500">WireGuard Tunnel IP</dt><dd class="mt-1 font-mono text-sm text-gray-900">{{ $router->vpnPeer?->tunnel_ip ?? 'Not assigned' }}</dd></div>
                        <div><dt class="text-xs text-gray-500">API Username</dt><dd class="mt-1 font-mono text-sm text-gray-900">{{ $router->api_username }}</dd></div>
                        <div><dt class="text-xs text-gray-500">API Password</dt><dd class="mt-1 text-sm text-gray-900">Configured and stored securely</dd></div>
                    </dl>
                </section>

                <section class="rounded-xl border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-200 p-5">
                        <h2 class="font-semibold">HotSpot Configuration</h2>
                    </div>
                    <div class="space-y-5 p-5">
                        <label class="flex items-start gap-3 rounded-lg border border-gray-200 p-4">
                            <input type="checkbox" x-model="enableHotspot" class="mt-1">
                            <span><span class="block text-sm font-medium text-gray-900">Enable HotSpot</span><span class="mt-1 block text-xs text-gray-500">Configure HotSpot service on this router.</span></span>
                        </label>
                        <div x-show="enableHotspot" x-cloak class="grid gap-5 sm:grid-cols-2">
                            <div>
                                <label for="hotspot_network" class="mb-1 block text-sm font-medium">HotSpot Network</label>
                                <input id="hotspot_network" name="hotspot_network" required x-bind:disabled="!enableHotspot"
                                       value="{{ old('hotspot_network', $router->hotspot_config['network'] ?? config('services.mikrotik.hotspot_network')) }}"
                                       class="w-full rounded-lg border-gray-300 bg-white font-mono text-gray-900 shadow-sm">
                            </div>
                            <div>
                                <label for="gateway_ip" class="mb-1 block text-sm font-medium">Gateway IP</label>
                                <input id="gateway_ip" name="gateway_ip" required x-model="gatewayIp" x-bind:disabled="!enableHotspot"
                                       value="{{ old('gateway_ip', $router->hotspot_config['gateway'] ?? config('services.mikrotik.gateway')) }}"
                                       class="w-full rounded-lg border-gray-300 bg-white font-mono text-gray-900 shadow-sm">
                            </div>
                            <div>
                                <label for="dns_servers" class="mb-1 block text-sm font-medium">DNS Servers</label>
                                <input id="dns_servers" name="dns_servers" required x-bind:disabled="!enableHotspot"
                                       value="{{ old('dns_servers', $router->hotspot_config['dns'] ?? implode(',', (array) config('services.mikrotik.dns_servers')) ) }}"
                                       class="w-full rounded-lg border-gray-300 bg-white font-mono text-gray-900 shadow-sm">
                            </div>
                            <div>
                                <label for="hotspot_domain" class="mb-1 block text-sm font-medium">Router HotSpot DNS name</label>
                                <div class="flex items-center">
                                    <input id="hotspot_domain" name="hotspot_domain" required x-bind:disabled="!enableHotspot"
                                           value="{{ old('hotspot_domain', $router->hotspot_config['domain'] ?? config('services.mikrotik.hotspot_domain')) }}"
                                           class="min-w-0 flex-1 rounded-lg border-gray-300 bg-white font-mono text-gray-900 shadow-sm">
                                </div>
                                <p class="mt-1 text-xs text-gray-500">This is the RouterOS HotSpot profile DNS name, not the customer voucher portal URL.</p>
                            </div>
                        </div>
                        <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-950">
                            <p><span class="font-semibold">Customer voucher portal:</span>
                                <a class="break-all font-mono underline" href="{{ $voucherPortalUrl }}" target="_blank" rel="noopener">{{ $voucherPortalUrl }}</a>
                            </p>
                            <p class="mt-2"><span class="font-semibold">Router HotSpot login after setup:</span>
                                <span class="font-mono" x-text="gatewayIp ? `http://${gatewayIp}/login` : 'Set a gateway IP to preview'">{{ $routerLoginUrl ?? 'Set a gateway IP to preview' }}</span>
                            </p>
                            <p class="mt-2 text-xs text-blue-800">The app sends customers to the router login endpoint after voucher activation; the portal URL is where they browse packages or redeem a voucher.</p>
                        </div>
                    </div>
                </section>

                <section class="rounded-xl border border-blue-200 bg-blue-50 p-5">
                    <h2 class="font-semibold text-blue-950">Provisioning review</h2>
                    <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-blue-900">
                        <li>Verify the enrolled router identity, RouterOS 7 version, live API-SSL connection, and selected interface links.</li>
                        <li>Refuse unknown bridge/resource ownership, conflicting address ranges, and existing PPP accounts/sessions.</li>
                        <li>Create a router-local backup and verify it exists before making changes.</li>
                        <li>Configure the selected HotSpot/PPPoE services and opted-in built-in Wi-Fi as an open voucher-protected HotSpot network. Leave WAN, management, existing IP firewall rules, DNS, and API-SSL settings unchanged; RouterOS may add HotSpot dynamic rules and the script adds configured walled-garden entries.</li>
                    </ul>
                    <p class="mt-3 text-xs text-blue-800">If any live check is incomplete or conflicts with the plan, provisioning stops without applying changes. Keep the backup until you have tested customer authentication and accounting.</p>
                </section>

                <label class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    <input type="checkbox" name="confirm_dedicated_router" value="1" required class="mt-1">
                    <span>I confirm this is a dedicated router, the WAN and recovery/management ports above are correct, I have out-of-band Winbox recovery, and I authorize the listed HotSpot/PPPoE changes.</span>
                </label>

                @if (str_contains(strtolower((string) $router->hardware_model), 'chr'))
                    <p class="rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-700">
                        PPPoE is intentionally not configured in the CHR HotSpot lab. It requires a separate physical router interface or VLAN and separately managed subscriber accounts.
                    </p>
                @endif

                <div class="flex justify-between">
                    <a href="{{ route('operator.routers.onboarding', ['tenant' => $tenant->slug, 'router' => $router->id]) }}"
                       class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Back</a>
                    <button type="submit" class="rounded-lg bg-orange-600 px-6 py-3 text-sm font-semibold text-white hover:bg-orange-500">
                        Create and configure router
                    </button>
                </div>
            </form>
            @endif
        </div>
    </div>
</x-app-layout>
