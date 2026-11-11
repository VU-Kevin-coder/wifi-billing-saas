<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-2 text-sm text-gray-500">
            <a href="{{ route('operator.routers.index', ['tenant' => $tenant->slug]) }}" class="hover:text-gray-900">MikroTik Routers</a>
            <span aria-hidden="true">&rsaquo;</span>
            <a href="{{ route('operator.routers.show', ['tenant' => $tenant->slug, 'router' => $router->id]) }}" class="hover:text-gray-900">{{ $router->name }}</a>
            <span aria-hidden="true">&rsaquo;</span>
            <span class="font-medium text-gray-900">Edit</span>
        </div>
    </x-slot>

    <div class="min-h-screen bg-gray-50 py-8">
        <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Edit router</h1>
                <p class="mt-1 text-sm text-gray-600">Update the router's saved details and connection settings. Changes here do not automatically reconfigure the physical router.</p>
            </div>

            @if ($errors->any())
                <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700" role="alert">
                    <p class="font-semibold">Please correct the following:</p>
                    <ul class="mt-2 list-inside list-disc">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form x-data="{ hasWireless: @js((bool) old('has_wireless', $router->has_wireless)) }" method="POST" action="{{ route('operator.routers.update', ['tenant' => $tenant->slug, 'router' => $router->id]) }}" class="space-y-6">
                @csrf
                @method('PUT')

                <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">Router details</h2>
                    <div class="mt-5 grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="name" class="mb-1 block text-sm font-medium text-gray-700">Router name</label>
                            <input id="name" name="name" required maxlength="255" value="{{ old('name', $router->name) }}"
                                   class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="serial_number" class="mb-1 block text-sm font-medium text-gray-700">Serial number</label>
                            <input id="serial_number" name="serial_number" maxlength="255" value="{{ old('serial_number', $router->serial_number) }}"
                                   class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="site_id" class="mb-1 block text-sm font-medium text-gray-700">Operating site</label>
                            <select id="site_id" name="site_id" class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">Unassigned</option>
                                @foreach ($sites as $site)
                                    <option value="{{ $site->id }}" @selected(old('site_id', $router->site_id) == $site->id)>{{ $site->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="hardware_model" class="mb-1 block text-sm font-medium text-gray-700">Hardware model</label>
                            <input id="hardware_model" name="hardware_model" maxlength="255" value="{{ old('hardware_model', $router->hardware_model) }}"
                                   class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="management_ip" class="mb-1 block text-sm font-medium text-gray-700">Management IP / hostname</label>
                            <input id="management_ip" name="management_ip" maxlength="255" value="{{ old('management_ip', $router->management_ip) }}"
                                   class="block w-full rounded-lg border-gray-300 font-mono shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('management_ip') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="management_subnet" class="mb-1 block text-sm font-medium text-gray-700">Allowed API source subnet</label>
                            <input id="management_subnet" name="management_subnet" maxlength="18"
                                   value="{{ old('management_subnet', $hotspotConfig['management_subnet'] ?? '') }}"
                                   placeholder="192.168.88.0/24"
                                   class="block w-full rounded-lg border-gray-300 font-mono shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('management_subnet') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="api_port" class="mb-1 block text-sm font-medium text-gray-700">API port</label>
                            <input id="api_port" name="api_port" type="number" min="1" max="65535" required value="{{ old('api_port', $router->api_port ?: 8728) }}"
                                   class="block w-full rounded-lg border-gray-300 font-mono shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="api_username" class="mb-1 block text-sm font-medium text-gray-700">API username</label>
                            <input id="api_username" name="api_username" maxlength="100" value="{{ old('api_username', $router->api_username) }}"
                                   class="block w-full rounded-lg border-gray-300 font-mono shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div class="sm:col-span-2">
                            <label for="api_password" class="mb-1 block text-sm font-medium text-gray-700">Replace API password</label>
                            <input id="api_password" name="api_password" type="password" maxlength="255" autocomplete="new-password"
                                   class="block w-full rounded-lg border-gray-300 font-mono shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <p class="mt-1 text-xs text-gray-500">Leave blank to keep the current encrypted password.</p>
                            @error('api_password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <label class="flex items-start gap-3 rounded-lg border border-gray-200 p-4">
                            <input type="checkbox" name="has_wireless" value="1" x-model="hasWireless" @checked(old('has_wireless', $router->has_wireless))
                                   class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-gray-900">Router has wireless</span>
                        </label>
                        <div x-show="hasWireless" x-cloak class="sm:col-span-2">
                            <label for="wifi_ssid" class="mb-1 block text-sm font-medium text-gray-700">Wi-Fi name (SSID)</label>
                            <input id="wifi_ssid" name="wifi_ssid" maxlength="32" value="{{ old('wifi_ssid', $router->wifi_ssid) }}"
                                   placeholder="{{ $tenant->name }}"
                                   class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <p class="mt-1 text-xs text-gray-500">Leave blank to use the selected site or your business name. The SSID is configured without changing existing Wi-Fi security settings.</p>
                            @error('wifi_ssid') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </section>

                <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm sm:p-8">
                    <h2 class="text-lg font-semibold text-gray-900">Hotspot setup</h2>
                    <label class="mt-5 flex items-center gap-3">
                        <input type="checkbox" name="enable_hotspot" value="1" @checked(old('enable_hotspot', $router->enable_hotspot))
                               class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span class="text-sm font-medium text-gray-900">Enable hotspot management</span>
                    </label>
                    <div class="mt-5 grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="hotspot_network" class="mb-1 block text-sm font-medium text-gray-700">Hotspot network</label>
                            <input id="hotspot_network" name="hotspot_network" maxlength="50" value="{{ old('hotspot_network', $hotspotConfig['network'] ?? '') }}"
                                   class="block w-full rounded-lg border-gray-300 font-mono shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('hotspot_network') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="gateway_ip" class="mb-1 block text-sm font-medium text-gray-700">Gateway IP</label>
                            <input id="gateway_ip" name="gateway_ip" maxlength="50" value="{{ old('gateway_ip', $hotspotConfig['gateway'] ?? '') }}"
                                   class="block w-full rounded-lg border-gray-300 font-mono shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('gateway_ip') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="dns_servers" class="mb-1 block text-sm font-medium text-gray-700">DNS servers</label>
                            <input id="dns_servers" name="dns_servers" maxlength="100" value="{{ old('dns_servers', $hotspotConfig['dns'] ?? '') }}"
                                   class="block w-full rounded-lg border-gray-300 font-mono shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('dns_servers') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="hotspot_domain" class="mb-1 block text-sm font-medium text-gray-700">Hotspot login domain</label>
                            <input id="hotspot_domain" name="hotspot_domain" maxlength="100" value="{{ old('hotspot_domain', $hotspotConfig['domain'] ?? '') }}"
                                   class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('hotspot_domain') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <fieldset class="mt-6">
                        <legend class="text-sm font-medium text-gray-700">Ethernet ports for client access</legend>
                        <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                            @foreach (['ether2', 'ether3', 'ether4', 'ether5'] as $port)
                                <label class="flex items-center gap-2 rounded-lg border border-gray-200 p-3 text-sm text-gray-700">
                                    <input type="checkbox" name="ethernet_ports[]" value="{{ $port }}"
                                           @checked(in_array($port, old('ethernet_ports', $router->ethernet_ports ?? []), true))
                                           class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    {{ $port }}
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                </section>

                <div class="flex justify-end gap-3">
                    <a href="{{ route('operator.routers.show', ['tenant' => $tenant->slug, 'router' => $router->id]) }}"
                       class="rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</a>
                    <button type="submit" class="rounded-lg bg-blue-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">Save router</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
