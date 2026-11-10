<form method="POST" action="{{ route('operator.settings.sms.update', ['tenant' => $tenant->slug]) }}" @change="hasChanges = true">
    @csrf
    @method('PUT')

    <section class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
        <div class="p-6 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-900">SMS Gateway Configuration</h3>
            <p class="text-sm text-gray-500 mt-1">Configure SMS gateway for sending notifications to customers.</p>
        </div>

        <div class="p-6 space-y-6">
            <label class="flex items-start gap-3">
                <input type="hidden" name="sms_enabled" value="0">
                <input type="checkbox" name="sms_enabled" value="1" @checked(old('sms_enabled', $settings['sms_enabled'])) class="mt-1 rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                <span>
                    <span class="block text-sm font-medium text-gray-900">Enable SMS Notifications</span>
                    <span class="block text-xs text-gray-500 mt-1">Enable sending SMS notifications to customers.</span>
                </span>
            </label>

            <div>
                <label for="sms_gateway" class="block text-sm font-medium text-gray-700">SMS Gateway</label>
                <select id="sms_gateway" name="sms_gateway" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="mambo" @selected(old('sms_gateway', $settings['sms_gateway']) === 'mambo')>Mambo SMS (Uganda)</option>
                    <option value="africastalking" @selected(old('sms_gateway', $settings['sms_gateway']) === 'africastalking')>Africa's Talking</option>
                </select>
                <p class="text-xs text-gray-500 mt-1">Mambo SMS is the recommended gateway for Uganda.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="sms_api_key" class="block text-sm font-medium text-gray-700">API Key</label>
                    <input id="sms_api_key" type="password" name="sms_api_key" autocomplete="new-password" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <p class="text-xs text-gray-500 mt-1">{{ $settings['sms_api_key'] !== '' ? 'A key is saved. Leave blank to keep it unchanged.' : 'Paste the API key from your SMS dashboard.' }}</p>
                </div>
                <div>
                    <label for="sms_sender_id" class="block text-sm font-medium text-gray-700">Sender ID</label>
                    <input id="sms_sender_id" type="text" name="sms_sender_id" value="{{ old('sms_sender_id', $settings['sms_sender_id']) }}" maxlength="11" placeholder="e.g. ACME" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <p class="text-xs text-gray-500 mt-1">Optional; max 11 characters. If empty, your company name will be used.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
        <div class="p-6 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-900">Expiry Reminder Notifications</h3>
            <p class="text-sm text-gray-500 mt-1">Send customers an SMS shortly before their active internet plan expires.</p>
        </div>
        <div class="p-6 space-y-5">
            <label class="flex items-start gap-3">
                <input type="hidden" name="notify_expiry_sms_reminder" value="0">
                <input type="checkbox" name="notify_expiry_sms_reminder" value="1" @checked(old('notify_expiry_sms_reminder', $settings['notify_expiry_sms_reminder'])) class="mt-1 rounded border-gray-300 text-blue-600 shadow-sm">
                <span>
                    <span class="block text-sm font-medium text-gray-900">Enable expiry reminder SMS</span>
                    <span class="block text-xs text-gray-500 mt-1">Send a reminder using the configured SMS gateway and this business's SMS opt-in.</span>
                </span>
            </label>

            <div>
                <label for="notify_expiry_reminder_mins" class="block text-sm font-medium text-gray-700 mb-1">Send reminder before expiry</label>
                <input id="notify_expiry_reminder_mins" name="notify_expiry_reminder_mins" type="number" min="1" max="43200" step="1" list="expiry-reminder-minute-presets" value="{{ old('notify_expiry_reminder_mins', $settings['notify_expiry_reminder_mins']) }}" required class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                <datalist id="expiry-reminder-minute-presets">
                    <option value="30">30 minutes</option>
                    <option value="60">1 hour</option>
                    <option value="1440">24 hours</option>
                </datalist>
                <p class="text-xs text-gray-500 mt-1">Enter minutes (1–43,200). Common choices: 30, 60, or 1,440 minutes; custom values are supported.</p>
                @error('notify_expiry_reminder_mins') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="template_expiry_reminder" class="block text-sm font-medium text-gray-700 mb-1">Expiry reminder message</label>
                <textarea id="template_expiry_reminder" name="template_expiry_reminder" rows="5" required maxlength="2000" class="block w-full rounded-md border-gray-300 shadow-sm font-mono text-sm">{{ old('template_expiry_reminder', $settings['template_expiry_reminder']) }}</textarea>
                <p class="text-xs text-gray-500 mt-1">Placeholders: @company_name, @package_name, @hours_left, @expiry_date, @portal_url, @support_number</p>
                @error('template_expiry_reminder') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
        </div>
    </section>

    <div x-show="hasChanges" x-transition class="fixed bottom-8 left-1/2 transform -translate-x-1/2 z-50 bg-gray-900 text-white px-6 py-4 rounded-xl shadow-2xl flex items-center gap-6">
        <div>
            <p class="text-sm font-bold">Unsaved changes</p>
            <p class="text-xs text-gray-300">Save before leaving this page.</p>
        </div>
        <div class="flex gap-2">
            <button type="button" @click="hasChanges = false; $el.closest('form').reset()" class="bg-gray-800 hover:bg-gray-700 text-white px-4 py-2 rounded-lg text-sm font-medium">Discard</button>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">Save Settings</button>
        </div>
    </div>
</form>
