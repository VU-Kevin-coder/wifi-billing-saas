<form method="POST" action="{{ route('operator.settings.hotspot.update', ['tenant' => $tenant->slug]) }}" @change="hasChanges = true">
    @csrf
    @method('PUT')

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
        <div class="p-6 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-900">Hotspot Settings</h3>
            <p class="text-sm text-gray-500 mt-1">Configure voucher rules, device behavior, and hotspot access.</p>
        </div>

        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label for="customer_id_prefix" class="block text-sm font-medium text-gray-700">Customer ID Prefix <span class="text-red-500">*</span></label>
                <input id="customer_id_prefix" type="text" name="customer_id_prefix" value="{{ old('customer_id_prefix', $settings['customer_id_prefix']) }}" required maxlength="10" pattern="[A-Za-z0-9]+" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                <p class="text-xs text-gray-500 mt-1">Letters and numbers only (for example, SE001).</p>
                @error('customer_id_prefix') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="voucher_length" class="block text-sm font-medium text-gray-700">Mobile Money Voucher Code Length</label>
                <select id="voucher_length" name="voucher_length" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    @foreach ([6, 8, 10] as $length)
                        <option value="{{ $length }}" @selected(old('voucher_length', $settings['voucher_length']) == $length)>{{ $length }} characters</option>
                    @endforeach
                </select>
                @error('voucher_length') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="printed_voucher_length" class="block text-sm font-medium text-gray-700">Printed Vouchers: Default Length</label>
                <select id="printed_voucher_length" name="printed_voucher_length" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    @foreach ([6, 8] as $length)
                        <option value="{{ $length }}" @selected(old('printed_voucher_length', $settings['printed_voucher_length']) == $length)>{{ $length }} characters</option>
                    @endforeach
                </select>
                <p class="text-xs text-gray-500 mt-1">Default for Create Voucher and Generate Voucher Sheet.</p>
                @error('printed_voucher_length') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="voucher_format" class="block text-sm font-medium text-gray-700">Printed Vouchers: Default Format</label>
                <select id="voucher_format" name="voucher_format" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="alphanumeric" @selected(old('voucher_format', $settings['voucher_format']) === 'alphanumeric')>Alphanumeric (A-Z, 0-9)</option>
                    <option value="numeric" @selected(old('voucher_format', $settings['voucher_format']) === 'numeric')>Numeric (0-9)</option>
                </select>
                @error('voucher_format') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="voucher_prefix" class="block text-sm font-medium text-gray-700">Voucher Code Prefix</label>
                <input id="voucher_prefix" type="text" name="voucher_prefix" value="{{ old('voucher_prefix', $settings['voucher_prefix']) }}" maxlength="10" placeholder="e.g., A" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                <p class="text-xs text-gray-500 mt-1">Optional prefix added to voucher codes.</p>
                @error('voucher_prefix') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="prune_inactive_days" class="block text-sm font-medium text-gray-700">Prune Inactive Users After</label>
                <select id="prune_inactive_days" name="prune_inactive_days" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    @foreach ([30, 60, 90, 0] as $days)
                        <option value="{{ $days }}" @selected(old('prune_inactive_days', $settings['prune_inactive_days']) == $days)>{{ $days === 0 ? 'Never' : $days . ' days' }}</option>
                    @endforeach
                </select>
                <p class="text-xs text-gray-500 mt-1">Automatically delete hotspot users inactive for this period.</p>
                @error('prune_inactive_days') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="auto_reconnect_method" class="block text-sm font-medium text-gray-700">Auto-Reconnect Method</label>
                <select id="auto_reconnect_method" name="auto_reconnect_method" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="mac_cookie" @selected(old('auto_reconnect_method', $settings['auto_reconnect_method']) === 'mac_cookie')>MAC-Cookie (Recommended)</option>
                    <option value="ip_binding" @selected(old('auto_reconnect_method', $settings['auto_reconnect_method']) === 'ip_binding')>IP Binding</option>
                </select>
                <p class="text-xs text-gray-500 mt-1">Choose how returning devices are reconnected.</p>
                @error('auto_reconnect_method') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="device_remember_days" class="block text-sm font-medium text-gray-700">Device Remember Duration</label>
                <select id="device_remember_days" name="device_remember_days" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    @foreach ([30, 7, 60] as $days)
                        <option value="{{ $days }}" @selected(old('device_remember_days', $settings['device_remember_days']) == $days)>{{ $days }} days{{ $days === 30 ? ' (Recommended)' : '' }}</option>
                    @endforeach
                </select>
                <p class="text-xs text-gray-500 mt-1">How long the router remembers a device before requiring its voucher again.</p>
                @error('device_remember_days') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
        <div class="p-6 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-900">Agent Settings</h3>
            <p class="text-sm text-gray-500 mt-1">Configure agent portal access and voucher reselling.</p>
        </div>
        <div class="p-6 space-y-6">
            @foreach ([
                'enable_agent_portal' => ['Enable Agent Portal', 'Allow agents to log in and view their performance dashboard.'],
                'enable_agent_reselling' => ['Enable Agent Reselling', 'Allow agents to purchase vouchers for resale.'],
                'enable_pppoe_self_registration' => ['Enable PPPoE Self-Registration', 'Allow potential customers to register online for PPPoE internet service.'],
                'block_tethering' => ['Block Tethering / Hotspot Sharing', 'Block devices detected sharing their Wi-Fi connection.'],
                'extend_gateway_fee' => ['Extend Gateway Fee', 'Apply gateway fees to voucher extensions.'],
            ] as $key => [$label, $description])
                <label class="flex items-start gap-3">
                    <span class="flex items-center h-6">
                        <input type="hidden" name="{{ $key }}" value="0">
                        <input type="checkbox" name="{{ $key }}" value="1" @checked(old($key, $settings[$key])) class="rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500">
                    </span>
                    <span>
                        <span class="text-sm font-medium text-gray-900">{{ $label }}</span>
                        <span class="block text-xs text-gray-500 mt-1">{{ $description }}</span>
                    </span>
                </label>
            @endforeach

            <div>
                <label for="tethering_action" class="block text-sm font-medium text-gray-700">Action When Tethering Is Detected</label>
                <select id="tethering_action" name="tethering_action" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="block" @selected(old('tethering_action', $settings['tethering_action']) === 'block')>Block Connection</option>
                    <option value="log" @selected(old('tethering_action', $settings['tethering_action']) === 'log')>Log Activity Only</option>
                </select>
                @error('tethering_action') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>

    <div x-show="hasChanges" x-cloak class="fixed bottom-8 left-1/2 -translate-x-1/2 z-50 bg-gray-900 text-white px-6 py-4 rounded-xl shadow-2xl flex items-center gap-6" x-transition>
        <div>
            <p class="text-sm font-bold">Unsaved changes</p>
            <p class="text-xs text-gray-300">Save before leaving this page.</p>
        </div>
        <div class="flex gap-2">
            <button type="button" @click="hasChanges = false; $el.closest('form').reset()" class="bg-gray-800 hover:bg-gray-700 text-white px-4 py-2 rounded-lg text-sm font-medium">Discard</button>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">Save</button>
        </div>
    </div>
</form>
