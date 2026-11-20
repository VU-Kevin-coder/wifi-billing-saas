<x-app-layout>
    <div class="min-h-screen bg-gray-50 px-4 py-8 text-gray-900 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-4xl">
            <a href="{{ route('operator.routers.index') }}" class="text-sm text-gray-500 hover:text-gray-800">Mikro Tiks</a>
            <h1 class="mt-3 text-2xl font-bold text-gray-900">Add MikroTik Device</h1>
            <p class="mt-2 text-sm text-gray-600">Connect your MikroTik router to enable automated provisioning and management.</p>

            <div class="mt-8 grid grid-cols-3 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b-2 border-orange-500 p-4">
                    <span class="text-sm font-semibold text-orange-700">01 &nbsp; Connection</span>
                    <p class="mt-1 text-xs text-gray-500">Basic device information</p>
                </div>
                <div class="border-b border-gray-200 p-4">
                    <span class="text-sm font-semibold text-gray-600">02 &nbsp; Device Details</span>
                    <p class="mt-1 text-xs text-gray-500">Provisioning command</p>
                </div>
                <div class="border-b border-gray-200 p-4">
                    <span class="text-sm font-semibold text-gray-600">03 &nbsp; Service Setup</span>
                    <p class="mt-1 text-xs text-gray-500">Configure HotSpot</p>
                </div>
            </div>

            @if ($errors->any())
                <div class="mt-5 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700" role="alert">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('operator.routers.store') }}" class="mt-6 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                @csrf
                <input type="hidden" name="guided_onboarding" value="1">

                <h2 class="text-lg font-semibold text-gray-900">Connection</h2>
                <p class="mt-1 text-sm text-gray-600">Enter a name and identify the dedicated MikroTik router to connect.</p>

                <div class="mt-6 grid gap-5 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="name" class="mb-1 block text-sm font-medium text-gray-700">Router name</label>
                        <input id="name" name="name" required maxlength="255" value="{{ old('name', $defaultName) }}"
                               class="w-full rounded-lg border-gray-300 bg-white text-gray-900 shadow-sm focus:border-orange-500 focus:ring-orange-500">
                    </div>
                    <div>
                        <label for="site_id" class="mb-1 block text-sm font-medium text-gray-700">Operating site</label>
                        <select id="site_id" name="site_id" class="w-full rounded-lg border-gray-300 bg-white text-gray-900 shadow-sm focus:border-orange-500 focus:ring-orange-500">
                            <option value="">Unassigned</option>
                            @foreach ($sites as $site)
                                <option value="{{ $site->id }}" @selected(old('site_id') == $site->id)>{{ $site->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="hardware_model" class="mb-1 block text-sm font-medium text-gray-700">Hardware model</label>
                        <input id="hardware_model" name="hardware_model" maxlength="255" value="{{ old('hardware_model') }}"
                               placeholder="e.g. hAP ax², RB4011"
                               class="w-full rounded-lg border-gray-300 bg-white text-gray-900 shadow-sm focus:border-orange-500 focus:ring-orange-500">
                    </div>

                    <p class="text-sm text-gray-600 sm:col-span-2">Customer authentication uses cloud RADIUS. Router management is restricted to the WireGuard tunnel and API-SSL.</p>

                    <label class="flex items-start gap-3 rounded-lg border border-gray-200 p-4 sm:col-span-2">
                        <input type="checkbox" name="has_wireless" value="1" @checked(old('has_wireless'))>
                        <span><span class="block text-sm font-medium text-gray-900">Router has wireless</span><span class="mt-1 block text-xs text-gray-500">Set this if the router has built-in Wi-Fi.</span></span>
                    </label>
                    <div class="sm:col-span-2">
                        <label for="wifi_ssid" class="mb-1 block text-sm font-medium text-gray-700">Wi-Fi name (SSID)</label>
                        <input id="wifi_ssid" name="wifi_ssid" maxlength="32" value="{{ old('wifi_ssid') }}"
                               placeholder="{{ $tenant->name }}"
                               class="w-full rounded-lg border-gray-300 bg-white text-gray-900 shadow-sm focus:border-orange-500 focus:ring-orange-500">
                        <p class="mt-1 text-xs text-gray-500">Wireless settings are applied only if you enable the wireless option.</p>
                    </div>
                </div>

                <div class="mt-8 flex justify-end">
                    <button type="submit" class="rounded-lg bg-orange-600 px-6 py-3 text-sm font-semibold text-white hover:bg-orange-500">
                        Next: Connect router
                    </button>
                </div>
            </form>

            @unless ($wireGuardReady)
                <p class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                    Secure onboarding is unavailable until the WireGuard hub is enabled, the public HTTPS application URL is configured, and the router-reachable WireGuard UDP endpoint is set.
                </p>
            @endunless
        </div>
    </div>
</x-app-layout>
