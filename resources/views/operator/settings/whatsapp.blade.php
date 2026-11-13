<form method="POST" action="{{ route('operator.settings.whatsapp.update', ['tenant' => $tenant->slug]) }}" @change="hasChanges = true">
    @csrf
    @method('PUT')

    <section class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
        <div class="p-6 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-900">WhatsApp Configuration</h3>
            <p class="text-sm text-gray-500 mt-1">Configure WhatsApp messaging for customer notifications.</p>
        </div>

        <div class="p-6 space-y-6">
            <label class="flex items-start gap-3">
                <input type="hidden" name="whatsapp_enabled" value="0">
                <input type="checkbox" name="whatsapp_enabled" value="1" @checked(old('whatsapp_enabled', $settings['whatsapp_enabled'])) class="mt-1 rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                <span>
                    <span class="block text-sm font-medium text-gray-900">Enable WhatsApp Notifications</span>
                    <span class="block text-xs text-gray-500 mt-1">Enable sending notifications via WhatsApp.</span>
                </span>
            </label>

            <div>
                <label for="whatsapp_gateway" class="block text-sm font-medium text-gray-700">WhatsApp Gateway</label>
                <input id="whatsapp_gateway" type="text" name="whatsapp_gateway" value="{{ old('whatsapp_gateway', $settings['whatsapp_gateway']) }}" maxlength="100" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="whatsapp_api_key" class="block text-sm font-medium text-gray-700">WhatsApp API Key</label>
                    <input id="whatsapp_api_key" type="password" name="whatsapp_api_key" autocomplete="new-password" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <p class="text-xs text-gray-500 mt-1">{{ $settings['whatsapp_api_key'] !== '' ? 'A key is saved. Leave blank to keep it unchanged.' : 'Enter the API key for your WhatsApp provider.' }}</p>
                </div>
                <div>
                    <label for="whatsapp_device_id" class="block text-sm font-medium text-gray-700">Device ID</label>
                    <input id="whatsapp_device_id" type="text" name="whatsapp_device_id" value="{{ old('whatsapp_device_id', $settings['whatsapp_device_id']) }}" maxlength="255" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
            </div>

            <div>
                <label for="whatsapp_webhook_secret" class="block text-sm font-medium text-gray-700">Webhook Secret</label>
                <input id="whatsapp_webhook_secret" type="password" name="whatsapp_webhook_secret" autocomplete="new-password" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                <p class="text-xs text-gray-500 mt-1">{{ $settings['whatsapp_webhook_secret'] !== '' ? 'A secret is saved. Leave blank to keep it unchanged.' : 'Optional secret for a future webhook integration.' }}</p>
            </div>

            <label class="flex items-start gap-3">
                <input type="hidden" name="whatsapp_chatbot_enabled" value="0">
                <input type="checkbox" name="whatsapp_chatbot_enabled" value="1" @checked(old('whatsapp_chatbot_enabled', $settings['whatsapp_chatbot_enabled'])) class="mt-1 rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                <span>
                    <span class="block text-sm font-medium text-gray-900">Enable WhatsApp Chatbot</span>
                    <span class="block text-xs text-gray-500 mt-1">Allow customers to buy vouchers, renew PPPoE, and check status by messaging your number.</span>
                </span>
            </label>
        </div>
    </section>

    <div x-show="hasChanges" x-transition class="fixed bottom-8 left-1/2 transform -translate-x-1/2 z-50 bg-gray-900 text-white px-6 py-4 rounded-xl shadow-2xl flex items-center gap-6">
        <div>
            <p class="text-sm font-bold">Unsaved changes</p>
            <p class="text-xs text-gray-300">Save before leaving this page.</p>
        </div>
        <div class="flex gap-2">
            <button type="button" @click="const form = $el.closest('form'); window.confirmAction('Discard all unsaved WhatsApp settings?').then(confirmed => { if (confirmed) { hasChanges = false; form.reset(); } })" class="bg-gray-800 hover:bg-gray-700 text-white px-4 py-2 rounded-lg text-sm font-medium">Discard</button>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">Save Credentials</button>
        </div>
    </div>
</form>
