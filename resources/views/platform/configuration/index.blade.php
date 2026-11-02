<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-2 text-sm text-gray-500">
            <a href="{{ route('platform.tenants.index') }}" class="hover:text-gray-900">Platform administration</a>
            <span>&rsaquo;</span>
            <span class="text-gray-900 font-medium">Configuration</span>
        </div>
    </x-slot>

    <div class="py-8 bg-gray-50 min-h-screen" x-data="{ 
        activeOverlay: null,
        openOverlay(name) { this.activeOverlay = name; },
        closeOverlay() { this.activeOverlay = null; }
    }">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            
            <!-- Page Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Configuration</h1>
                    <p class="text-gray-500 text-sm mt-1">Platform-wide defaults, integrations and operational policies. Not operator business settings.</p>
                </div>
                <div class="flex items-center gap-2.5">
                    <button type="button" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-xl text-xs font-semibold shadow-sm hover:bg-gray-50 transition-colors">
                        View history
                    </button>
                    <button type="button" onclick="document.getElementById('general-settings-form').submit()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl text-xs font-semibold shadow-sm transition-colors">
                        Save all changes
                    </button>
                </div>
            </div>

            <!-- Flash Alert -->
            @if(session('success') || session('status'))
                <div class="p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm flex items-center justify-between shadow-sm">
                    <div class="flex items-center gap-2.5">
                        <svg class="w-5 h-5 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>{{ session('success') ?? session('status') }}</span>
                    </div>
                </div>
            @endif

            @if($errors->any())
                <div class="p-4 rounded-xl bg-red-50 border border-red-200 text-red-800 text-sm shadow-sm space-y-1">
                    <div class="font-semibold">Please correct the following errors:</div>
                    <ul class="list-disc list-inside text-xs space-y-0.5">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <!-- Production Banner -->
            <div class="bg-blue-50/80 border border-blue-200/80 rounded-2xl p-4 flex items-start gap-3.5 shadow-sm">
                <div class="w-8 h-8 rounded-xl bg-blue-100 flex items-center justify-center text-blue-600 shrink-0 mt-0.5">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-semibold text-blue-900">Production configuration &bull; Version 2.18</p>
                    <p class="text-xs text-blue-700/90 mt-1 leading-relaxed">
                        Published by {{ auth()->user()->name }} on {{ now()->format('d M Y') }}. New tenant defaults apply automatically on creation; security and operational changes are strictly audited.
                    </p>
                </div>
            </div>

            <!-- General & Regional Defaults -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200/80 overflow-hidden">
                <form id="general-settings-form" method="POST" action="{{ route('platform.configuration.general.update') }}" class="p-6">
                    @csrf
                    @method('PUT')
                    
                    <div class="mb-6 pb-4 border-b border-gray-100">
                        <h2 class="text-base font-bold text-gray-900">General & regional defaults</h2>
                        <p class="text-xs text-gray-500 mt-1">Identity, localization, currency and display standards for new tenants.</p>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-6">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1.5">Platform name</label>
                            <input type="text" name="platform_name" value="{{ old('platform_name', $settings['platform_name']) }}" 
                                   class="block w-full rounded-xl border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-xs py-2.5 px-3">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1.5">Default country</label>
                            <select name="default_country" class="block w-full rounded-xl border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-xs py-2.5 px-3 bg-white">
                                <option value="Uganda" {{ old('default_country', $settings['default_country']) == 'Uganda' ? 'selected' : '' }}>Uganda</option>
                                <option value="Kenya" {{ old('default_country', $settings['default_country']) == 'Kenya' ? 'selected' : '' }}>Kenya</option>
                                <option value="Tanzania" {{ old('default_country', $settings['default_country']) == 'Tanzania' ? 'selected' : '' }}>Tanzania</option>
                                <option value="Rwanda" {{ old('default_country', $settings['default_country']) == 'Rwanda' ? 'selected' : '' }}>Rwanda</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1.5">Currency</label>
                            <select name="currency" class="block w-full rounded-xl border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-xs py-2.5 px-3 bg-white">
                                <option value="UGX" {{ old('currency', $settings['currency']) == 'UGX' ? 'selected' : '' }}>UGX - Ugandan Shilling</option>
                                <option value="KES" {{ old('currency', $settings['currency']) == 'KES' ? 'selected' : '' }}>KES - Kenyan Shilling</option>
                                <option value="USD" {{ old('currency', $settings['currency']) == 'USD' ? 'selected' : '' }}>USD - US Dollar</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1.5">Timezone</label>
                            <select name="timezone" class="block w-full rounded-xl border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-xs py-2.5 px-3 bg-white">
                                <option value="Africa/Kampala" {{ old('timezone', $settings['timezone']) == 'Africa/Kampala' ? 'selected' : '' }}>Africa/Kampala - EAT (UTC+3)</option>
                                <option value="Africa/Nairobi" {{ old('timezone', $settings['timezone']) == 'Africa/Nairobi' ? 'selected' : '' }}>Africa/Nairobi - EAT (UTC+3)</option>
                                <option value="UTC" {{ old('timezone', $settings['timezone']) == 'UTC' ? 'selected' : '' }}>UTC</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1.5">Date format</label>
                            <select name="date_format" class="block w-full rounded-xl border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-xs py-2.5 px-3 bg-white">
                                <option value="DD MMM YYYY" {{ old('date_format', $settings['date_format']) == 'DD MMM YYYY' ? 'selected' : '' }}>DD MMM YYYY (e.g. 02 Nov 2026)</option>
                                <option value="YYYY-MM-DD" {{ old('date_format', $settings['date_format']) == 'YYYY-MM-DD' ? 'selected' : '' }}>YYYY-MM-DD (e.g. 2026-11-02)</option>
                                <option value="DD/MM/YYYY" {{ old('date_format', $settings['date_format']) == 'DD/MM/YYYY' ? 'selected' : '' }}>DD/MM/YYYY (e.g. 02/11/2026)</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1.5">Default Language</label>
                            <select name="language" class="block w-full rounded-xl border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-xs py-2.5 px-3 bg-white">
                                <option value="English (Uganda)" {{ old('language', $settings['language']) == 'English (Uganda)' ? 'selected' : '' }}>English (Uganda)</option>
                                <option value="English (UK)" {{ old('language', $settings['language']) == 'English (UK)' ? 'selected' : '' }}>English (UK)</option>
                                <option value="English (US)" {{ old('language', $settings['language']) == 'English (US)' ? 'selected' : '' }}>English (US)</option>
                            </select>
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-xs font-semibold text-gray-700 mb-1.5">Default Platform Commission (%)</label>
                            <div class="relative rounded-xl shadow-sm max-w-sm">
                                <input type="number" step="0.01" min="0" max="100" name="default_commission_rate" 
                                       value="{{ old('default_commission_rate', $settings['default_commission_rate']) }}" 
                                       class="block w-full rounded-xl border-gray-300 pr-10 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-xs py-2.5 px-3">
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3">
                                    <span class="text-gray-400 text-xs font-bold">%</span>
                                </div>
                            </div>
                            <p class="text-[11px] text-gray-500 mt-1.5">This percentage is automatically deducted from operator voucher sales for platform revenue share.</p>
                        </div>
                    </div>
                    
                    <div class="flex items-center justify-end pt-4 border-t border-gray-100">
                        <button type="submit" class="bg-gray-900 hover:bg-gray-800 text-white px-4 py-2 rounded-xl text-xs font-semibold shadow-sm transition-colors">
                            Save General Settings
                        </button>
                    </div>
                </form>
            </div>

            <!-- Platform Integrations -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200/80 overflow-hidden">
                <div class="p-6 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        <h2 class="text-base font-bold text-gray-900">Platform integrations</h2>
                        <p class="text-xs text-gray-500 mt-1">Platform-owned credentials only. Operator merchant accounts remain isolated in their respective workspaces.</p>
                    </div>
                    <button type="button" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-600 hover:text-gray-900 px-3 py-1.5 rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors">
                        <svg class="w-3.5 h-3.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        Run all connections check
                    </button>
                </div>
                
                <div class="divide-y divide-gray-100">
                    <!-- MTN MoMo -->
                    <div class="p-4 sm:p-5 flex items-center justify-between hover:bg-gray-50/70 transition-colors">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-amber-500/10 text-amber-600 flex items-center justify-center font-bold text-xs shrink-0">
                                MTN
                            </div>
                            <div>
                                <p class="text-xs font-bold text-gray-900">MTN MoMo</p>
                                <p class="text-[11px] text-gray-500">Platform subscription billing & tenant voucher collections</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-4">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium bg-gray-100 text-gray-600">
                                <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>
                                {{ $integrations['mtn']['status'] }}
                            </span>
                            <button @click="openOverlay('mtn')" type="button" class="text-xs font-semibold text-blue-600 hover:text-blue-800 transition-colors">
                                Configure
                            </button>
                        </div>
                    </div>

                    <!-- Airtel Money -->
                    <div class="p-4 sm:p-5 flex items-center justify-between hover:bg-gray-50/70 transition-colors">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-red-500/10 text-red-600 flex items-center justify-center font-bold text-xs shrink-0">
                                AIR
                            </div>
                            <div>
                                <p class="text-xs font-bold text-gray-900">Airtel Money</p>
                                <p class="text-[11px] text-gray-500">Platform subscription billing & tenant voucher collections</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-4">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium bg-gray-100 text-gray-600">
                                <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>
                                {{ $integrations['airtel']['status'] }}
                            </span>
                            <button @click="openOverlay('airtel')" type="button" class="text-xs font-semibold text-blue-600 hover:text-blue-800 transition-colors">
                                Configure
                            </button>
                        </div>
                    </div>

                    <!-- Email delivery -->
                    <div class="p-4 sm:p-5 flex items-center justify-between hover:bg-gray-50/70 transition-colors">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-blue-500/10 text-blue-600 flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-gray-900">Email delivery</p>
                                <p class="text-[11px] text-gray-500">Invoices, password resets, security notifications & notices</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-4">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium bg-gray-100 text-gray-600">
                                <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>
                                {{ $integrations['email']['status'] }}
                            </span>
                            <button @click="openOverlay('email')" type="button" class="text-xs font-semibold text-blue-600 hover:text-blue-800 transition-colors">
                                Configure
                            </button>
                        </div>
                    </div>

                    <!-- SMS Gateway -->
                    <div class="p-4 sm:p-5 flex items-center justify-between hover:bg-gray-50/70 transition-colors">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-purple-500/10 text-purple-600 flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-gray-900">SMS gateway</p>
                                <p class="text-[11px] text-gray-500">Critical platform alerts, tenant OTPs & billing reminders</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-4">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium bg-gray-100 text-gray-600">
                                <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>
                                {{ $integrations['sms']['status'] }}
                            </span>
                            <button @click="openOverlay('sms')" type="button" class="text-xs font-semibold text-blue-600 hover:text-blue-800 transition-colors">
                                Configure
                            </button>
                        </div>
                    </div>
                </div>

                <div class="p-4 bg-gray-50 border-t border-gray-200/80 text-xs text-gray-500 flex items-center gap-2">
                    <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                    </svg>
                    <span>Secrets are encrypted at rest using AES-256. Webhook signatures are strictly verified and audited.</span>
                </div>
            </div>

            <!-- Security & Privileged Access -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200/80 overflow-hidden">
                <form method="POST" action="{{ route('platform.configuration.security.update') }}" class="p-6">
                    @csrf
                    @method('PUT')
                    
                    <div class="mb-6 pb-4 border-b border-gray-100">
                        <h2 class="text-base font-bold text-gray-900">Security & privileged access</h2>
                        <p class="text-xs text-gray-500 mt-1">High-risk action controls and platform administration security posture.</p>
                    </div>

                    <div class="space-y-5">
                        <!-- Require 2FA -->
                        <div class="flex items-center justify-between py-2">
                            <div>
                                <p class="text-xs font-semibold text-gray-900">Require 2FA for platform administrators</p>
                                <p class="text-[11px] text-gray-500 mt-0.5">Force all platform admins to use TOTP authentication before accessing the administration panel.</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="enforce_2fa" value="1" class="sr-only peer" {{ $settings['enforce_2fa'] ? 'checked' : '' }}>
                                <div class="w-10 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>

                        <!-- Email Alerts -->
                        <div class="flex items-center justify-between py-2 border-t border-gray-100">
                            <div>
                                <p class="text-xs font-semibold text-gray-900">Email alerts for critical platform events</p>
                                <p class="text-[11px] text-gray-500 mt-0.5">Notify superadmins when tenant routers experience massive downtime or webhooks fail repeatedly.</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="notify_email_alerts" value="1" class="sr-only peer" {{ $settings['notify_email_alerts'] ? 'checked' : '' }}>
                                <div class="w-10 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>

                        <!-- SMS Alerts -->
                        <div class="flex items-center justify-between py-2 border-t border-gray-100">
                            <div>
                                <p class="text-xs font-semibold text-gray-900">SMS alerts for high-priority outages</p>
                                <p class="text-[11px] text-gray-500 mt-0.5">Send direct SMS dispatch to platform on-call engineers for core service interruptions.</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="notify_sms_alerts" value="1" class="sr-only peer" {{ $settings['notify_sms_alerts'] ? 'checked' : '' }}>
                                <div class="w-10 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>

                        <!-- Session Timeout -->
                        <div class="pt-4 border-t border-gray-100 max-w-sm">
                            <label class="block text-xs font-semibold text-gray-700 mb-1.5">Session Inactivity Timeout (minutes)</label>
                            <input type="number" min="5" max="1440" name="session_timeout" value="{{ old('session_timeout', $settings['session_timeout']) }}"
                                   class="block w-full rounded-xl border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-xs py-2 px-3">
                            <p class="text-[11px] text-gray-500 mt-1">Admin sessions will automatically terminate after this period of inactivity.</p>
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end pt-4 border-t border-gray-100">
                        <button type="submit" class="bg-gray-900 hover:bg-gray-800 text-white px-4 py-2 rounded-xl text-xs font-semibold shadow-sm transition-colors">
                            Save Security Settings
                        </button>
                    </div>
                </form>
            </div>

        </div>

        <!-- Slide-over Drawer for Batch 2 Integrations Placeholder -->
        <div x-show="activeOverlay" 
             class="fixed inset-0 z-50 overflow-hidden" 
             style="display: none;"
             x-description="Slide-over modal">
            <div class="absolute inset-0 bg-gray-900/40 backdrop-blur-xs transition-opacity" 
                 @click="closeOverlay()"
                 x-show="activeOverlay"
                 x-transition:enter="ease-out duration-300"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="ease-in duration-200"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"></div>

            <div class="fixed inset-y-0 right-0 max-w-full flex pl-10">
                <div class="w-screen max-w-md bg-white shadow-2xl flex flex-col justify-between"
                     x-show="activeOverlay"
                     x-transition:enter="transform transition ease-in-out duration-300 sm:duration-500"
                     x-transition:enter-start="translate-x-full"
                     x-transition:enter-end="translate-x-0"
                     x-transition:leave="transform transition ease-in-out duration-300 sm:duration-500"
                     x-transition:leave-start="translate-x-0"
                     x-transition:leave-end="translate-x-full">
                    
                    <div class="p-6 border-b border-gray-100 flex items-center justify-between">
                        <div>
                            <h3 class="text-sm font-bold text-gray-900 capitalize" x-text="activeOverlay + ' Integration Credentials'"></h3>
                            <p class="text-xs text-gray-500 mt-0.5">Configure platform API credentials and webhooks</p>
                        </div>
                        <button @click="closeOverlay()" type="button" class="p-1 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="p-6 flex-1 overflow-y-auto space-y-4">
                        <div class="p-4 rounded-xl bg-blue-50 border border-blue-200 text-xs text-blue-700 leading-relaxed">
                            Integration settings drawer is ready for Batch 2 credentials and webhooks setup.
                        </div>
                    </div>

                    <div class="p-4 border-t border-gray-100 flex justify-end gap-2 bg-gray-50">
                        <button @click="closeOverlay()" type="button" class="px-4 py-2 text-xs font-semibold text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>

