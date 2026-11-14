<x-app-layout>
    <div class="min-h-screen bg-gray-50 px-4 py-8 sm:px-6 lg:px-8"
         x-data="{
             step: {{ $errors->hasAny(['hotspot_network', 'gateway_ip', 'dns_servers', 'hotspot_domain']) ? 3 : ($errors->hasAny(['management_ip', 'management_subnet', 'api_port', 'api_username', 'api_password']) ? 2 : 1) }},
             hasWireless: @js((bool) old('has_wireless', false)),
             enableHotspot: @js((bool) old('enable_hotspot', true)),
             next(section, nextStep) {
                 const invalid = this.$refs[section].querySelector(':invalid');
                 if (invalid) {
                     invalid.reportValidity();
                     return;
                 }
                 this.step = nextStep;
             }
         }">
        <div class="mx-auto max-w-4xl">
            <div class="mb-8">
                <a href="{{ route('operator.routers.index') }}" class="text-sm font-medium text-gray-500 hover:text-gray-800">Routers</a>
                <h1 class="mt-2 text-2xl font-bold text-gray-900">Add MikroTik Router</h1>
                <p class="mt-1 text-sm text-gray-600">Add and configure a MikroTik router for hotspot billing and management.</p>
            </div>

            @if ($errors->any())
                <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700" role="alert">
                    {{ $errors->first() }}
                </div>
            @endif

            <div class="mb-6 grid grid-cols-3 gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                @foreach (['Router details', 'API Credentials', 'Service Setup'] as $stepNumber => $stepLabel)
                    @php($stepIndex = $stepNumber + 1)
                    <div class="flex items-center gap-2 sm:gap-3">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold"
                              :class="step >= {{ $stepIndex }} ? 'bg-orange-500 text-white' : 'bg-gray-100 text-gray-500'">
                            {{ str_pad((string) $stepIndex, 2, '0', STR_PAD_LEFT) }}
                        </span>
                        <span class="text-xs font-semibold text-gray-700 sm:text-sm">{{ $stepLabel }}</span>
                    </div>
                @endforeach
            </div>

            <form method="POST" action="{{ route('operator.routers.store') }}" class="space-y-6">
                @csrf

                <section x-show="step === 1" x-ref="connection" class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">Router details</h2>
                    <p class="mt-1 text-sm text-gray-500">Identify this device and optionally assign it to a site.</p>

                    <div class="mt-6 grid gap-5 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label for="name" class="mb-1 block text-sm font-medium text-gray-700">Router name</label>
                            <input id="name" name="name" type="text" required maxlength="255"
                                   value="{{ old('name', $defaultName) }}"
                                   class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-orange-500 focus:ring-orange-500">
                            @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="site_id" class="mb-1 block text-sm font-medium text-gray-700">Operating site</label>
                            <select id="site_id" name="site_id"
                                    class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-orange-500 focus:ring-orange-500">
                                <option value="">Unassigned</option>
                                @foreach ($sites as $site)
                                    <option value="{{ $site->id }}" @selected(old('site_id') == $site->id)>{{ $site->name }}</option>
                                @endforeach
                            </select>
                            @error('site_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="hardware_model" class="mb-1 block text-sm font-medium text-gray-700">Hardware model</label>
                            <input id="hardware_model" name="hardware_model" type="text" maxlength="255"
                                   value="{{ old('hardware_model') }}" placeholder="e.g. hAP ax³, CCR2004"
                                   class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-orange-500 focus:ring-orange-500">
                            @error('hardware_model') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div class="sm:col-span-2">
                            <label class="flex items-start gap-3 rounded-lg border border-gray-200 p-4">
                                <input type="checkbox" name="has_wireless" value="1" x-model="hasWireless" @checked(old('has_wireless'))
                                       class="mt-0.5 rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                                <span>
                                    <span class="block text-sm font-medium text-gray-900">Router has wireless</span>
                                    <span class="mt-1 block text-xs text-gray-500">Enable this for MikroTik models with built-in Wi-Fi.</span>
                                </span>
                            </label>
                        </div>
                        <div x-show="hasWireless" x-cloak class="sm:col-span-2">
                            <label for="wifi_ssid" class="mb-1 block text-sm font-medium text-gray-700">Wi-Fi name (SSID)</label>
                            <input id="wifi_ssid" name="wifi_ssid" type="text" maxlength="32"
                                   value="{{ old('wifi_ssid') }}" placeholder="{{ $tenant->name }}"
                                   class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-orange-500 focus:ring-orange-500">
                            <p class="mt-1 text-xs text-gray-500">Leave blank to use the selected site or your business name. The SSID is configured without changing existing Wi-Fi security settings.</p>
                            @error('wifi_ssid') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="mt-8 flex justify-between">
                        <a href="{{ route('operator.routers.index') }}" class="rounded-lg bg-gray-100 px-5 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-200">Cancel</a>
                        <button type="button" @click="next('connection', 2)"
                                class="rounded-lg bg-orange-500 px-6 py-2.5 text-sm font-semibold text-white hover:bg-orange-600">
                            Next
                        </button>
                    </div>
                </section>

                <section x-show="step === 2" x-ref="apiAccess" class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">Router API Credentials</h2>
                    <p class="mt-1 text-sm text-gray-500">These credentials are saved with this router; the password is encrypted at rest. Leave it blank to generate one and include it in the setup script.</p>

                    <div class="mt-6 grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="management_ip" class="mb-1 block text-sm font-medium text-gray-700">Router IP / Hostname</label>
                            <input id="management_ip" name="management_ip" type="text" maxlength="255"
                                   value="{{ old('management_ip') }}" placeholder="192.168.88.1"
                                   class="block w-full rounded-lg border-gray-300 font-mono shadow-sm focus:border-orange-500 focus:ring-orange-500">
                            <p class="mt-1 text-xs text-gray-500">Router address reachable from the paired site agent or the local test environment.</p>
                            @error('management_ip') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="management_subnet" class="mb-1 block text-sm font-medium text-gray-700">Allowed API source subnet</label>
                            <input id="management_subnet" name="management_subnet" type="text" maxlength="18"
                                   value="{{ old('management_subnet') }}" placeholder="192.168.88.0/24"
                                   class="block w-full rounded-lg border-gray-300 font-mono shadow-sm focus:border-orange-500 focus:ring-orange-500">
                            <p class="mt-1 text-xs text-gray-500">Restricts API/API-SSL access. If omitted, the generated script leaves API access disabled.</p>
                            @error('management_subnet') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="api_port" class="mb-1 block text-sm font-medium text-gray-700">API Port</label>
                            <input id="api_port" name="api_port" type="number" min="1" max="65535"
                                   value="{{ old('api_port', $apiPort) }}"
                                   class="block w-full rounded-lg border-gray-300 font-mono shadow-sm focus:border-orange-500 focus:ring-orange-500">
                            <p class="mt-1 text-xs text-gray-500">8728 uses the unencrypted API; 8729 uses API-SSL. Restrict its allowed source subnet.</p>
                            @error('api_port') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="api_username" class="mb-1 block text-sm font-medium text-gray-700">API Username</label>
                            <input id="api_username" name="api_username" type="text" maxlength="100"
                                   value="{{ old('api_username', config('services.mikrotik.api_username')) }}"
                                   class="block w-full rounded-lg border-gray-300 font-mono shadow-sm focus:border-orange-500 focus:ring-orange-500">
                            @error('api_username') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="api_password" class="mb-1 block text-sm font-medium text-gray-700">API Password</label>
                            <input id="api_password" name="api_password" type="password" maxlength="255"
                                   placeholder="Leave blank to auto-generate"
                                   class="block w-full rounded-lg border-gray-300 font-mono shadow-sm focus:border-orange-500 focus:ring-orange-500">
                            <p class="mt-1 text-xs text-gray-500">Leave blank to automatically generate a secure password in the script.</p>
                            @error('api_password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="mt-8 flex justify-between">
                        <button type="button" @click="step = 1"
                                class="rounded-lg bg-gray-100 px-5 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-200">
                            Back
                        </button>
                        <button type="button" @click="step = 3"
                                class="rounded-lg bg-orange-500 px-6 py-2.5 text-sm font-semibold text-white hover:bg-orange-600">
                            Next
                        </button>
                    </div>
                </section>

                <section x-show="step === 3" class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">Service setup</h2>
                    <p class="mt-1 text-sm text-gray-500">Choose whether this router will manage a hotspot and which ports to include.</p>

                    <label class="mt-6 flex items-start gap-3 rounded-lg border border-gray-200 p-4">
                        <input type="hidden" name="enable_hotspot" :value="enableHotspot ? 1 : 0">
                        <input type="checkbox" value="1" x-model="enableHotspot"
                               class="mt-0.5 rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                        <span>
                            <span class="block text-sm font-medium text-gray-900">Enable hotspot management</span>
                            <span class="mt-1 block text-xs text-gray-500">Configure the hotspot network on this router.</span>
                        </span>
                    </label>

                    <div x-show="enableHotspot" class="mt-5 grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="hotspot_network" class="mb-1 block text-sm font-medium text-gray-700">Hotspot network</label>
                            <input id="hotspot_network" name="hotspot_network" type="text" maxlength="50"
                                   value="{{ old('hotspot_network', $hotspotDefaults['network']) }}"
                                   class="block w-full rounded-lg border-gray-300 font-mono shadow-sm focus:border-orange-500 focus:ring-orange-500">
                            @error('hotspot_network') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="gateway_ip" class="mb-1 block text-sm font-medium text-gray-700">Gateway IP</label>
                            <input id="gateway_ip" name="gateway_ip" type="text" maxlength="50"
                                   value="{{ old('gateway_ip', $hotspotDefaults['gateway']) }}"
                                   class="block w-full rounded-lg border-gray-300 font-mono shadow-sm focus:border-orange-500 focus:ring-orange-500">
                            @error('gateway_ip') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="dns_servers" class="mb-1 block text-sm font-medium text-gray-700">DNS servers</label>
                            <input id="dns_servers" name="dns_servers" type="text" maxlength="100"
                                   value="{{ old('dns_servers', $hotspotDefaults['dns']) }}"
                                   class="block w-full rounded-lg border-gray-300 font-mono shadow-sm focus:border-orange-500 focus:ring-orange-500">
                            @error('dns_servers') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="hotspot_domain" class="mb-1 block text-sm font-medium text-gray-700">Hotspot login domain</label>
                            <input id="hotspot_domain" name="hotspot_domain" type="text" maxlength="100"
                                   value="{{ old('hotspot_domain', $hotspotDefaults['domain']) }}"
                                   class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-orange-500 focus:ring-orange-500">
                            @error('hotspot_domain') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <fieldset class="mt-6">
                        <legend class="text-sm font-medium text-gray-700">Ethernet ports for client access</legend>
                        <p class="mt-1 text-xs text-gray-500">The platform WAN interface ({{ $wanInterface }}) is excluded from client ports.</p>
                        <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                            @foreach (['ether2', 'ether3', 'ether4', 'ether5'] as $port)
                                <label class="flex items-center gap-2 rounded-lg border border-gray-200 p-3 text-sm text-gray-700">
                                    <input type="checkbox" name="ethernet_ports[]" value="{{ $port }}"
                                           @checked(in_array($port, old('ethernet_ports', $defaultClientPorts), true))
                                           class="rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                                    <span class="font-mono">{{ $port }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <div class="mt-8 flex justify-between">
                        <button type="button" @click="step = 2"
                                class="rounded-lg bg-gray-100 px-5 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-200">
                            Back
                        </button>
                        <button type="submit"
                                class="rounded-lg bg-orange-500 px-6 py-2.5 text-sm font-semibold text-white hover:bg-orange-600">
                            Add router
                        </button>
                    </div>
                </section>
            </form>
        </div>
    </div>
</x-app-layout>
