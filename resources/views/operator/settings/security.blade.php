<form method="POST" action="{{ route('operator.settings.security.update', ['tenant' => $tenant->slug]) }}" @change="hasChanges = true">
    @csrf
    @method('PUT')

    <section class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
        <div class="p-6 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-900">Two-Factor Authentication</h3>
            <p class="text-sm text-gray-500 mt-1">Configure two-factor authentication access for {{ $tenant->name }}.</p>
        </div>

        <div class="p-6 space-y-6">
            <label class="flex items-start gap-3">
                <input type="hidden" name="enable_2fa" value="0">
                <input type="checkbox" name="enable_2fa" value="1" @checked(old('enable_2fa', $settings['enable_2fa'] || $settings['require_2fa'])) class="mt-1 rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                <span>
                    <span class="block text-sm font-medium text-gray-900">Enable two-factor authentication</span>
                    <span class="block text-xs text-gray-500 mt-1">Allow workspace users to secure their accounts with an authenticator app.</span>
                </span>
            </label>

            <label class="flex items-start gap-3">
                <input type="hidden" name="require_2fa" value="0">
                <input type="checkbox" name="require_2fa" value="1" @checked(old('require_2fa', $settings['require_2fa'])) class="mt-1 rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                <span>
                    <span class="block text-sm font-medium text-gray-900">Require two-factor authentication for all workspace users</span>
                    <span class="block text-xs text-gray-500 mt-1">Users without an enrolled method will be directed to set up 2FA before continuing. Enabling this also enables 2FA for the workspace.</span>
                </span>
            </label>

        </div>
    </section>

    <section class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6 p-6">
        <h3 class="text-lg font-semibold text-gray-900">Your account enrollment</h3>
        <p class="text-sm text-gray-500 mt-1">
            Your account is {{ auth()->user()->hasEnabledTwoFactorAuthentication() ? 'enrolled in' : 'not enrolled in' }} two-factor authentication.
        </p>
        <a href="{{ route('profile.security.index') }}" class="mt-4 inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
            Manage your 2FA enrollment
        </a>
    </section>

    <div x-show="hasChanges" x-transition class="fixed bottom-8 left-1/2 transform -translate-x-1/2 z-50 bg-gray-900 text-white px-6 py-4 rounded-xl shadow-2xl flex items-center gap-6">
        <div>
            <p class="text-sm font-bold">Unsaved changes</p>
            <p class="text-xs text-gray-300">Save before leaving this page.</p>
        </div>
        <div class="flex gap-2">
            <button type="button" @click="const form = $el.closest('form'); window.confirmAction('Discard all unsaved security settings?').then(confirmed => { if (confirmed) { hasChanges = false; form.reset(); } })" class="bg-gray-800 hover:bg-gray-700 text-white px-4 py-2 rounded-lg text-sm font-medium">Discard</button>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">Save Security Settings</button>
        </div>
    </div>
</form>
