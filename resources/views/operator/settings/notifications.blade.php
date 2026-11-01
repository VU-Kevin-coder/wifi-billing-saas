<form method="POST" action="{{ route('operator.settings.notifications.update', ['tenant' => $tenant->slug]) }}" @change="hasChanges = true">
    @csrf
    @method('PUT')

    <section class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
        <div class="p-6 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-900">Voucher and Payment Notifications</h3>
            <p class="text-sm text-gray-500 mt-1">Control voucher delivery and optional payment receipts.</p>
        </div>
        <div class="p-6 space-y-5">
            <label class="flex items-start gap-3">
                <input type="hidden" name="notify_voucher_sms" value="0">
                <input type="checkbox" name="notify_voucher_sms" value="1" @checked(old('notify_voucher_sms', $settings['notify_voucher_sms'])) class="mt-1 rounded border-gray-300 text-blue-600 shadow-sm">
                <span><span class="block text-sm font-medium text-gray-900">Send voucher code by SMS</span><span class="block text-xs text-gray-500 mt-1">Send voucher credentials after successful payment.</span></span>
            </label>
            <label class="flex items-start gap-3">
                <input type="hidden" name="notify_credentials_sms" value="0">
                <input type="checkbox" name="notify_credentials_sms" value="1" @checked(old('notify_credentials_sms', $settings['notify_credentials_sms'])) class="mt-1 rounded border-gray-300 text-blue-600 shadow-sm">
                <span><span class="block text-sm font-medium text-gray-900">Send hotspot credentials message</span><span class="block text-xs text-gray-500 mt-1">Send hotspot credentials after online login.</span></span>
            </label>
            <div>
                <label for="template_voucher" class="block text-sm font-medium text-gray-700 mb-1">Hotspot voucher/credentials message</label>
                <textarea id="template_voucher" name="template_voucher" rows="5" required class="block w-full rounded-md border-gray-300 shadow-sm font-mono text-sm">{{ old('template_voucher', $settings['template_voucher']) }}</textarea>
                <p class="text-xs text-gray-500 mt-1">Variables: @password, @package_name, @validity, @company_name, @expiry_date, @support_number, @portal_url</p>
            </div>
            <label class="flex items-start gap-3">
                <input type="hidden" name="notify_receipt_sms" value="0">
                <input type="checkbox" name="notify_receipt_sms" value="1" @checked(old('notify_receipt_sms', $settings['notify_receipt_sms'])) class="mt-1 rounded border-gray-300 text-blue-600 shadow-sm">
                <span><span class="block text-sm font-medium text-gray-900">Send separate payment receipt SMS</span><span class="block text-xs text-gray-500 mt-1">Send a receipt in addition to the voucher/credentials message.</span></span>
            </label>
        </div>
    </section>

    <section class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
        <div class="p-6 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-900">Expiry Notifications</h3>
            <p class="text-sm text-gray-500 mt-1">SMS sent when a user package has expired.</p>
        </div>
        <div class="p-6 space-y-5">
            <label class="flex items-start gap-3">
                <input type="hidden" name="notify_expiry_sms" value="0">
                <input type="checkbox" name="notify_expiry_sms" value="1" @checked(old('notify_expiry_sms', $settings['notify_expiry_sms'])) class="mt-1 rounded border-gray-300 text-blue-600 shadow-sm">
                <span class="block text-sm font-medium text-gray-900">Send expiry notifications to hotspot users</span>
            </label>
            <div>
                <label for="template_expiry" class="block text-sm font-medium text-gray-700 mb-1">Hotspot expiry notification</label>
                <textarea id="template_expiry" name="template_expiry" rows="5" required class="block w-full rounded-md border-gray-300 shadow-sm font-mono text-sm">{{ old('template_expiry', $settings['template_expiry']) }}</textarea>
            </div>
        </div>
    </section>

    <section class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
        <div class="p-6 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-900">Expiry Reminder Notifications</h3>
            <p class="text-sm text-gray-500 mt-1">SMS sent before a user package expires.</p>
        </div>
        <div class="p-6 space-y-5">
            <label class="flex items-start gap-3">
                <input type="hidden" name="notify_expiry_sms_reminder" value="0">
                <input type="checkbox" name="notify_expiry_sms_reminder" value="1" @checked(old('notify_expiry_sms_reminder', $settings['notify_expiry_sms_reminder'])) class="mt-1 rounded border-gray-300 text-blue-600 shadow-sm">
                <span class="block text-sm font-medium text-gray-900">Send expiry reminder notifications to hotspot users</span>
            </label>
            <div>
                <label for="notify_expiry_reminder_mins" class="block text-sm font-medium text-gray-700 mb-1">Send reminder before expiry</label>
                <select id="notify_expiry_reminder_mins" name="notify_expiry_reminder_mins" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="30" @selected((int) old('notify_expiry_reminder_mins', $settings['notify_expiry_reminder_mins']) === 30)>30 minutes before</option>
                    <option value="60" @selected((int) old('notify_expiry_reminder_mins', $settings['notify_expiry_reminder_mins']) === 60)>1 hour before</option>
                    <option value="1440" @selected((int) old('notify_expiry_reminder_mins', $settings['notify_expiry_reminder_mins']) === 1440)>24 hours before</option>
                </select>
            </div>
            <div>
                <label for="template_expiry_reminder" class="block text-sm font-medium text-gray-700 mb-1">Expiry reminder SMS</label>
                <textarea id="template_expiry_reminder" name="template_expiry_reminder" rows="5" required class="block w-full rounded-md border-gray-300 shadow-sm font-mono text-sm">{{ old('template_expiry_reminder', $settings['template_expiry_reminder']) }}</textarea>
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
