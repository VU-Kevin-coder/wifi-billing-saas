<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-2 text-sm text-gray-500">
            <a href="{{ route('platform.tenants.index') }}" class="hover:text-gray-900">Platform administration</a>
            <span>&rsaquo;</span>
            <span class="text-gray-900 font-medium">Configuration</span>
        </div>
    </x-slot>

    <div class="py-8 bg-gray-50 min-h-screen" x-data="{ 
        activeOverlay: '{{ session('overlay', '') }}',
        openOverlay(name) { this.activeOverlay = name; },
        closeOverlay() { this.activeOverlay = ''; }
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

            <!-- Flash Alerts -->
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

            @if(session('error'))
                <div class="p-4 rounded-xl bg-red-50 border border-red-200 text-red-800 text-sm flex items-center justify-between shadow-sm">
                    <div class="flex items-center gap-2.5">
                        <svg class="w-5 h-5 text-red-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>{{ session('error') }}</span>
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
                
                @php
                    $integrationItems = [
                        [
                            'key' => 'mtn',
                            'name' => 'MTN MoMo',
                            'desc' => 'Direct Uganda mobile money collections',
                            'label' => 'MTN',
                            'bg' => 'bg-yellow-500/10',
                            'text' => 'text-yellow-700',
                            'status' => $settings['mtn_status'],
                        ],
                        [
                            'key' => 'airtel',
                            'name' => 'Airtel Money',
                            'desc' => 'Direct Uganda mobile money collections',
                            'label' => 'AIR',
                            'bg' => 'bg-red-500/10',
                            'text' => 'text-red-600',
                            'status' => $settings['airtel_status'],
                        ],
                        [
                            'key' => 'pesapal',
                            'name' => 'Pesapal',
                            'desc' => 'Mobile Money (MTN/Airtel) & Card Collections',
                            'label' => 'PSP',
                            'bg' => 'bg-emerald-500/10',
                            'text' => 'text-emerald-600',
                            'status' => $settings['pesapal_status'],
                        ],
                        [
                            'key' => 'flutterwave',
                            'name' => 'Flutterwave payouts',
                            'desc' => 'Existing operator payout transfers',
                            'label' => 'FLW',
                            'bg' => 'bg-blue-500/10',
                            'text' => 'text-blue-600',
                            'status' => $settings['flutterwave_status'],
                        ],
                        [
                            'key' => 'email',
                            'name' => 'Email delivery (SMTP)',
                            'desc' => 'Invoices, password resets, security notifications & customer notices',
                            'label' => 'icon',
                            'bg' => 'bg-blue-500/10',
                            'text' => 'text-blue-600',
                            'status' => $settings['email_status'],
                        ],
                        [
                            'key' => 'sms',
                            'name' => 'SMS Gateway (' . ($settings['sms_provider'] === 'twilio' ? 'Twilio' : "Africa's Talking") . ')',
                            'desc' => 'Critical platform alerts, customer hotspot vouchers & OTPs',
                            'label' => 'icon',
                            'bg' => 'bg-purple-500/10',
                            'text' => 'text-purple-600',
                            'status' => $settings['sms_status'],
                        ],
                    ];
                @endphp

                <div class="divide-y divide-gray-100">
                    @foreach($integrationItems as $item)
                    <div class="p-4 sm:p-5 flex items-center justify-between hover:bg-gray-50/70 transition-colors">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl {{ $item['bg'] }} {{ $item['text'] }} flex items-center justify-center font-bold text-xs shrink-0">
                                @if($item['key'] === 'email')
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                    </svg>
                                @elseif($item['key'] === 'sms')
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                                    </svg>
                                @else
                                    {{ $item['label'] }}
                                @endif
                            </div>
                            <div>
                                <p class="text-xs font-bold text-gray-900">{{ $item['name'] }}</p>
                                <p class="text-[11px] text-gray-500">{{ $item['desc'] }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-4">
                            @php
                                $statusColor = match(true) {
                                    str_contains($item['status'], 'Connected') => 'bg-emerald-100 text-emerald-700',
                                    str_contains($item['status'], 'Configured') => 'bg-blue-100 text-blue-700',
                                    str_contains($item['status'], 'Failed') => 'bg-red-100 text-red-700',
                                    str_contains($item['status'], 'Not Tested') => 'bg-amber-100 text-amber-700',
                                    default => 'bg-gray-100 text-gray-600',
                                };
                                $dotColor = match(true) {
                                    str_contains($item['status'], 'Connected') => 'bg-emerald-500',
                                    str_contains($item['status'], 'Configured') => 'bg-blue-500',
                                    str_contains($item['status'], 'Failed') => 'bg-red-500',
                                    str_contains($item['status'], 'Not Tested') => 'bg-amber-500',
                                    default => 'bg-gray-400',
                                };
                            @endphp
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium {{ $statusColor }}">
                                <span class="w-1.5 h-1.5 rounded-full {{ $dotColor }}"></span>
                                {{ $item['status'] }}
                            </span>
                            <button @click="openOverlay('{{ $item['key'] }}')" type="button" class="text-xs font-semibold text-blue-600 hover:text-blue-800 transition-colors">
                                Configure
                            </button>
                        </div>
                    </div>
                    @endforeach
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

        <!-- MTN MoMo settings overlay -->
        <div x-show="activeOverlay === 'mtn'" class="fixed inset-0 z-50 overflow-hidden" x-cloak>
            <div x-show="activeOverlay === 'mtn'" x-transition class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="closeOverlay()"></div>
            <div class="absolute inset-y-0 right-0 max-w-full flex pl-10">
                <div x-show="activeOverlay === 'mtn'" x-transition class="w-screen max-w-md bg-white shadow-2xl flex flex-col">
                    <div class="px-6 py-5 border-b border-gray-200 flex justify-between items-center">
                        <div>
                            <h2 class="text-sm font-bold text-gray-900">MTN MoMo collections</h2>
                            <p class="text-[11px] text-gray-500">Uganda Collection API credentials</p>
                        </div>
                        <button type="button" @click="closeOverlay()" class="text-gray-500">Close</button>
                    </div>
                    <form method="POST" action="{{ route('platform.configuration.mtn.update') }}" class="flex flex-col h-full overflow-y-auto">
                        @csrf
                        @method('PUT')
                        <div class="flex-1 p-6 space-y-4">
                            <p class="text-xs text-gray-600">Credentials are encrypted at rest. Leave a saved secret blank to keep it. Callbacks are verified against MTN before vouchers are issued. MTN sandbox requests use EUR; production collections use UGX.</p>
                            <div>
                                <label for="mtn_environment" class="block text-[11px] font-semibold text-gray-700 mb-1">Environment</label>
                                <select id="mtn_environment" name="mtn_environment" class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3">
                                    <option value="sandbox" {{ $settings['mtn_environment'] === 'sandbox' ? 'selected' : '' }}>Sandbox</option>
                                    <option value="production" {{ $settings['mtn_environment'] === 'production' ? 'selected' : '' }}>Production</option>
                                </select>
                            </div>
                            <div>
                                <label for="mtn_subscription_key" class="block text-[11px] font-semibold text-gray-700 mb-1">Collection subscription key</label>
                                <input id="mtn_subscription_key" type="password" name="mtn_subscription_key" autocomplete="new-password" placeholder="{{ $settings['mtn_subscription_key_configured'] ? 'Saved - leave blank to keep current value' : 'Not configured' }}" class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3 font-mono">
                            </div>
                            <div>
                                <label for="mtn_api_user" class="block text-[11px] font-semibold text-gray-700 mb-1">API user UUID</label>
                                <input id="mtn_api_user" type="password" name="mtn_api_user" autocomplete="new-password" placeholder="{{ $settings['mtn_api_user_configured'] ? 'Saved - leave blank to keep current value' : 'Not configured' }}" class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3 font-mono">
                            </div>
                            <div>
                                <label for="mtn_api_key" class="block text-[11px] font-semibold text-gray-700 mb-1">API key</label>
                                <input id="mtn_api_key" type="password" name="mtn_api_key" autocomplete="new-password" placeholder="{{ $settings['mtn_api_key_configured'] ? 'Saved - leave blank to keep current value' : 'Not configured' }}" class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3 font-mono">
                            </div>
                            <div>
                                <label class="block text-[11px] font-semibold text-gray-700 mb-1">Webhook URL</label>
                                <input type="text" readonly value="{{ $settings['mtn_webhook_url'] }}" class="block w-full rounded-xl border-gray-300 bg-gray-50 text-[10px] py-2 px-3 font-mono">
                            </div>
                            <p class="text-[11px] text-gray-500">Status: {{ $settings['mtn_status'] }}</p>
                        </div>
                        <div class="px-6 py-4 border-t bg-gray-50 flex justify-end gap-2">
                            <button type="button" @click="closeOverlay()" class="px-4 py-2 text-xs font-semibold text-gray-700 bg-white border border-gray-300 rounded-xl">Cancel</button>
                            <button type="submit" class="px-4 py-2 text-xs font-semibold text-white bg-blue-600 rounded-xl">Save configuration</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Airtel Money settings overlay -->
        <div x-show="activeOverlay === 'airtel'" class="fixed inset-0 z-50 overflow-hidden" x-cloak>
            <div x-show="activeOverlay === 'airtel'" x-transition class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="closeOverlay()"></div>
            <div class="absolute inset-y-0 right-0 max-w-full flex pl-10">
                <div x-show="activeOverlay === 'airtel'" x-transition class="w-screen max-w-md bg-white shadow-2xl flex flex-col">
                    <div class="px-6 py-5 border-b border-gray-200 flex justify-between items-center">
                        <div>
                            <h2 class="text-sm font-bold text-gray-900">Airtel Money collections</h2>
                            <p class="text-[11px] text-gray-500">Uganda direct collection credentials</p>
                        </div>
                        <button type="button" @click="closeOverlay()" class="text-gray-500">Close</button>
                    </div>
                    <form method="POST" action="{{ route('platform.configuration.airtel.update') }}" class="flex flex-col h-full overflow-y-auto">
                        @csrf
                        @method('PUT')
                        <div class="flex-1 p-6 space-y-4">
                            <p class="text-xs text-gray-600">Credentials are encrypted at rest. Leave a saved secret blank to keep it. Payment callbacks are verified with Airtel before vouchers are issued.</p>
                            <div>
                                <label for="airtel_environment" class="block text-[11px] font-semibold text-gray-700 mb-1">Environment</label>
                                <select id="airtel_environment" name="airtel_environment" class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3">
                                    <option value="sandbox" {{ $settings['airtel_environment'] === 'sandbox' ? 'selected' : '' }}>Sandbox</option>
                                    <option value="production" {{ $settings['airtel_environment'] === 'production' ? 'selected' : '' }}>Production</option>
                                </select>
                            </div>
                            <div>
                                <label for="airtel_client_id" class="block text-[11px] font-semibold text-gray-700 mb-1">Client ID</label>
                                <input id="airtel_client_id" type="password" name="airtel_client_id" autocomplete="new-password" placeholder="{{ $settings['airtel_client_id_configured'] ? 'Saved - leave blank to keep current value' : 'Not configured' }}" class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3 font-mono">
                            </div>
                            <div>
                                <label for="airtel_client_secret" class="block text-[11px] font-semibold text-gray-700 mb-1">Client secret</label>
                                <input id="airtel_client_secret" type="password" name="airtel_client_secret" autocomplete="new-password" placeholder="{{ $settings['airtel_client_secret_configured'] ? 'Saved - leave blank to keep current value' : 'Not configured' }}" class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3 font-mono">
                            </div>
                            <div>
                                <label class="block text-[11px] font-semibold text-gray-700 mb-1">Webhook URL</label>
                                <input type="text" readonly value="{{ $settings['airtel_webhook_url'] }}" class="block w-full rounded-xl border-gray-300 bg-gray-50 text-[10px] py-2 px-3 font-mono">
                            </div>
                            <p class="text-[11px] text-gray-500">Status: {{ $settings['airtel_status'] }}</p>
                        </div>
                        <div class="px-6 py-4 border-t bg-gray-50 flex justify-end gap-2">
                            <button type="button" @click="closeOverlay()" class="px-4 py-2 text-xs font-semibold text-gray-700 bg-white border border-gray-300 rounded-xl">Cancel</button>
                            <button type="submit" class="px-4 py-2 text-xs font-semibold text-white bg-blue-600 rounded-xl">Save configuration</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

                <!-- Pesapal settings overlay -->
        <div x-show="activeOverlay === 'pesapal'" class="fixed inset-0 z-50 overflow-hidden" x-cloak>
            <div x-show="activeOverlay === 'pesapal'" x-transition class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="closeOverlay()"></div>
            <div class="absolute inset-y-0 right-0 max-w-full flex pl-10">
                <div x-show="activeOverlay === 'pesapal'" x-transition:enter="transform transition ease-in-out duration-500" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transform transition ease-in-out duration-300" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full" class="w-screen max-w-md bg-white shadow-2xl flex flex-col">
                    <div class="px-6 py-5 border-b border-gray-200 flex justify-between items-center bg-white sticky top-0 z-10">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 bg-emerald-500/10 rounded-xl flex items-center justify-center text-emerald-600 font-bold text-xs">PSP</div>
                            <div>
                                <h2 class="text-sm font-bold text-gray-900">Pesapal Gateway Configuration</h2>
                                <p class="text-[11px] text-gray-500">Mobile Money (MTN/Airtel) & Card Collections</p>
                            </div>
                        </div>
                        <button type="button" @click="closeOverlay()" class="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors" aria-label="Close configuration">Close</button>
                    </div>

                    <form method="POST" action="{{ route('platform.configuration.pesapal.update') }}" class="flex flex-col h-full overflow-y-auto">
                        @csrf
                        @method('PUT')
                        <div class="flex-1 p-6 space-y-5">
                            <div class="bg-blue-50 border border-blue-200/80 p-3.5 rounded-xl text-xs text-blue-800 leading-relaxed">
                                <p class="font-bold">Pesapal handles MTN and Airtel Uganda automatically.</p>
                                <p class="mt-1">Customers are redirected to Pesapal's secure checkout to choose their payment method and complete payment.</p>
                            </div>

                            <div>
                                <label for="pesapal_environment" class="block text-[11px] font-semibold text-gray-700 mb-1">Environment</label>
                                <select id="pesapal_environment" name="pesapal_environment" class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3">
                                    <option value="sandbox" {{ ($settings['pesapal_environment'] ?? 'sandbox') === 'sandbox' ? 'selected' : '' }}>Sandbox (cybqa.pesapal.com)</option>
                                    <option value="production" {{ ($settings['pesapal_environment'] ?? '') === 'production' ? 'selected' : '' }}>Production (pay.pesapal.com)</option>
                                </select>
                            </div>

                            <div class="space-y-4">
                                <div>
                                    <label for="pesapal_consumer_key" class="block text-[11px] font-semibold text-gray-700 mb-1">Consumer Key</label>
                                    <input id="pesapal_consumer_key" type="text" name="pesapal_consumer_key" value="{{ $settings['pesapal_consumer_key'] }}" autocomplete="off" class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3 font-mono">
                                </div>
                                <div>
                                    <label for="pesapal_consumer_secret" class="block text-[11px] font-semibold text-gray-700 mb-1">Consumer Secret</label>
                                    <input id="pesapal_consumer_secret" type="password" name="pesapal_consumer_secret" autocomplete="new-password" placeholder="{{ filled($settings['pesapal_consumer_secret']) ? 'Saved — enter new value to change' : 'Enter Consumer Secret' }}" class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3 font-mono">
                                </div>
                                <div>
                                    <label for="pesapal_ipn_id" class="block text-[11px] font-semibold text-gray-700 mb-1">IPN Notification ID (Optional)</label>
                                    <input id="pesapal_ipn_id" type="text" name="pesapal_ipn_id" value="{{ $settings['pesapal_ipn_id'] }}" placeholder="Registered IPN GUID from Pesapal" class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3 font-mono">
                                </div>
                            </div>

                            <div class="bg-gray-50 rounded-xl p-4 border border-gray-200">
                                <label class="block text-xs font-semibold text-gray-900 mb-1">Webhook / IPN URL</label>
                                <p class="text-[10px] text-gray-500 mb-2">Register this URL in your Pesapal merchant dashboard to receive real-time IPN updates.</p>
                                <div class="flex rounded-md shadow-sm">
                                    <input id="pesapal-ipn-url" type="text" readonly value="{{ url('/api/webhooks/pesapal') }}" class="min-w-0 flex-1 rounded-l-md border-gray-300 bg-white text-gray-600 font-mono text-[10px]">
                                    <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('pesapal-ipn-url').value)" class="inline-flex items-center px-3 rounded-r-md border border-l-0 border-gray-300 bg-gray-100 text-gray-600 text-xs hover:bg-gray-200 font-medium">Copy</button>
                                </div>
                            </div>

                            <p class="text-[11px] text-gray-500">Status: <span class="font-medium text-gray-700">{{ $settings['pesapal_status'] }}</span></p>
                        </div>

                        <div class="px-6 py-4 border-t bg-gray-50 flex justify-between items-center">
                            <p class="text-[10px] text-gray-400">Secrets encrypted at rest.</p>
                            <div class="flex gap-2">
                                <button type="button" @click="closeOverlay()" class="px-4 py-2 text-xs font-semibold text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50">Cancel</button>
                                <button type="submit" class="px-4 py-2 text-xs font-semibold text-white bg-blue-600 rounded-xl hover:bg-blue-700">Save configuration</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

<!-- Flutterwave settings overlay -->
        <div x-show="activeOverlay === 'flutterwave'" class="fixed inset-0 z-50 overflow-hidden" x-cloak>
            <div x-show="activeOverlay === 'flutterwave'" x-transition class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="closeOverlay()"></div>
            <div class="absolute inset-y-0 right-0 max-w-full flex pl-10">
                <div x-show="activeOverlay === 'flutterwave'" x-transition:enter="transform transition ease-in-out duration-500" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transform transition ease-in-out duration-300" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full" class="w-screen max-w-md bg-white shadow-2xl flex flex-col">
                    <div class="px-6 py-5 border-b border-gray-200 flex justify-between items-center bg-white sticky top-0 z-10">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 bg-blue-500/10 rounded-xl flex items-center justify-center text-blue-600 font-bold text-xs">FLW</div>
                            <div>
                                <h2 class="text-sm font-bold text-gray-900">Flutterwave payout compatibility</h2>
                                <p class="text-[11px] text-gray-500">Existing payouts and legacy collection transactions</p>
                            </div>
                        </div>
                        <button type="button" @click="closeOverlay()" class="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors" aria-label="Close configuration">Close</button>
                    </div>

                    <form method="POST" action="{{ route('platform.configuration.flutterwave.update') }}" class="flex flex-col h-full overflow-y-auto">
                        @csrf
                        @method('PUT')
                        <div class="flex-1 p-6 space-y-5">
                            <div class="bg-blue-50 border border-blue-200/80 p-3.5 rounded-xl text-xs text-blue-800 leading-relaxed">
                                <p class="font-bold">Status: {{ $settings['flutterwave_status'] }}</p>
                                <p class="mt-1">New portal collections use MTN MoMo and Airtel Money. Saved secrets remain available for payouts and legacy transactions; secrets are never sent back to this page.</p>
                            </div>

                            <div>
                                <label for="flutterwave_environment" class="block text-[11px] font-semibold text-gray-700 mb-1">Environment</label>
                                <select id="flutterwave_environment" name="flutterwave_environment" class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3 bg-white">
                                    <option value="sandbox" {{ $settings['flutterwave_environment'] === 'sandbox' ? 'selected' : '' }}>Sandbox</option>
                                    <option value="production" {{ $settings['flutterwave_environment'] === 'production' ? 'selected' : '' }}>Production</option>
                                </select>
                            </div>

                            <div class="space-y-3">
                                <div>
                                    <label for="flutterwave_public_key" class="block text-[11px] font-semibold text-gray-700 mb-1">Public key</label>
                                    <input id="flutterwave_public_key" type="text" name="flutterwave_public_key" value="{{ $settings['flutterwave_public_key'] }}" autocomplete="off" class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3 font-mono">
                                </div>
                                <div>
                                    <label for="flutterwave_secret_key" class="block text-[11px] font-semibold text-gray-700 mb-1">Secret key</label>
                                    <input id="flutterwave_secret_key" type="password" name="flutterwave_secret_key" autocomplete="new-password" placeholder="{{ $settings['flutterwave_secret_key_configured'] ? 'Saved - leave blank to keep current value' : 'Not configured' }}" class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3 font-mono">
                                </div>
                                <div>
                                    <label for="flutterwave_encryption_key" class="block text-[11px] font-semibold text-gray-700 mb-1">Encryption key</label>
                                    <input id="flutterwave_encryption_key" type="password" name="flutterwave_encryption_key" autocomplete="new-password" placeholder="{{ $settings['flutterwave_encryption_key_configured'] ? 'Saved - leave blank to keep current value' : 'Not configured' }}" class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3 font-mono">
                                </div>
                                <div>
                                    <label for="flutterwave_webhook_secret" class="block text-[11px] font-semibold text-gray-700 mb-1">Webhook secret hash</label>
                                    <input id="flutterwave_webhook_secret" type="password" name="flutterwave_webhook_secret" autocomplete="new-password" placeholder="{{ $settings['flutterwave_webhook_secret_configured'] ? 'Saved - leave blank to keep current value' : 'Not configured' }}" class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3 font-mono">
                                    <p class="mt-1 text-[10px] text-gray-500">This must match the secret hash configured in the Flutterwave dashboard.</p>
                                </div>
                            </div>

                            <div class="bg-gray-50 rounded-xl p-4 border border-gray-200">
                                <label class="block text-xs font-semibold text-gray-900 mb-1">Webhook URL</label>
                                <p class="text-[10px] text-gray-500 mb-2">Configure this URL in Flutterwave for legacy charge and payout transfer events.</p>
                                <div class="flex rounded-md shadow-sm">
                                    <input id="flutterwave-webhook-url" type="text" readonly value="{{ url('/api/webhooks/flutterwave') }}" class="min-w-0 flex-1 rounded-l-md border-gray-300 bg-white text-gray-600 font-mono text-[10px]">
                                    <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('flutterwave-webhook-url').value)" class="inline-flex items-center px-3 rounded-r-md border border-l-0 border-gray-300 bg-gray-100 text-gray-600 text-xs hover:bg-gray-200 font-medium">Copy</button>
                                </div>
                            </div>
                        </div>

                        <div class="px-6 py-4 border-t border-gray-200 bg-gray-50 flex justify-between items-center">
                            <p class="text-[10px] text-gray-500">Secrets encrypted at rest</p>
                            <div class="flex gap-2">
                                <button type="button" @click="closeOverlay()" class="px-4 py-2 text-xs font-semibold text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50">Cancel</button>
                                <button type="submit" class="px-4 py-2 text-xs font-semibold text-white bg-blue-600 rounded-xl hover:bg-blue-700">Save configuration</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ─── EMAIL (SMTP) OVERLAY ─── -->
        <div x-show="activeOverlay === 'email'" class="fixed inset-0 z-50 overflow-hidden" x-cloak>
            <div x-show="activeOverlay === 'email'" x-transition class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="closeOverlay()"></div>
            <div class="absolute inset-y-0 right-0 max-w-full flex pl-10">
                <div x-show="activeOverlay === 'email'" x-transition:enter="transform transition ease-in-out duration-500" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transform transition ease-in-out duration-300" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full" class="w-screen max-w-lg bg-white shadow-2xl flex flex-col">
                    <div class="px-6 py-5 border-b border-gray-200 flex justify-between items-center bg-white sticky top-0 z-10">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 bg-blue-500/10 rounded-xl flex items-center justify-center text-blue-600 shrink-0">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            </div>
                            <div>
                                <h2 class="text-sm font-bold text-gray-900">Email Gateway Configuration</h2>
                                <p class="text-[11px] text-gray-500">SMTP Connection, Sender Identity & Live Test</p>
                            </div>
                        </div>
                        <button @click="closeOverlay()" class="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    
                    <div class="flex-1 overflow-y-auto p-6 space-y-6">
                        <!-- Status Banner -->
                        <div class="p-3.5 rounded-xl text-xs flex items-center justify-between {{ str_contains($settings['email_status'], 'Connected') ? 'bg-emerald-50 border border-emerald-200 text-emerald-800' : (str_contains($settings['email_status'], 'Failed') ? 'bg-red-50 border border-red-200 text-red-800' : 'bg-blue-50 border border-blue-200/80 text-blue-800') }}">
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full {{ str_contains($settings['email_status'], 'Connected') ? 'bg-emerald-500' : (str_contains($settings['email_status'], 'Failed') ? 'bg-red-500' : 'bg-blue-500') }}"></span>
                                <span class="font-bold">Status: {{ $settings['email_status'] }}</span>
                            </div>
                            <span class="text-[11px] opacity-80">AES-256 Encrypted</span>
                        </div>

                        <!-- Credentials Form -->
                        <form id="email-settings-form" method="POST" action="{{ route('platform.configuration.email.update') }}" class="space-y-4">
                            @csrf
                            @method('PUT')

                            <div>
                                <h3 class="text-xs font-bold text-gray-900 mb-3 uppercase tracking-wider text-gray-500">1. SMTP Connection</h3>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                    <div class="sm:col-span-2">
                                        <label class="block text-[11px] font-semibold text-gray-700 mb-1">SMTP Host</label>
                                        <input type="text" name="smtp_host" value="{{ $settings['smtp_host'] }}" placeholder="smtp.gmail.com or smtp.mailgun.org" class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3 focus:ring-blue-500 focus:border-blue-500">
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-semibold text-gray-700 mb-1">Port</label>
                                        <input type="number" name="smtp_port" value="{{ $settings['smtp_port'] }}" placeholder="587" class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3 focus:ring-blue-500 focus:border-blue-500">
                                    </div>
                                </div>
                                <div class="mt-2.5">
                                    <label class="block text-[11px] font-semibold text-gray-700 mb-1">Encryption Protocol</label>
                                    <select name="smtp_encryption" class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3 bg-white focus:ring-blue-500 focus:border-blue-500">
                                        <option value="tls" {{ $settings['smtp_encryption'] === 'tls' ? 'selected' : '' }}>TLS (Port 587 - Recommended)</option>
                                        <option value="ssl" {{ $settings['smtp_encryption'] === 'ssl' ? 'selected' : '' }}>SSL (Port 465)</option>
                                        <option value="none" {{ $settings['smtp_encryption'] === 'none' ? 'selected' : '' }}>None (Port 25)</option>
                                    </select>
                                </div>
                            </div>

                            <div class="pt-2 border-t border-gray-100">
                                <h3 class="text-xs font-bold text-gray-900 mb-3 uppercase tracking-wider text-gray-500">2. Authentication</h3>
                                <div class="space-y-3">
                                    <div>
                                        <label class="block text-[11px] font-semibold text-gray-700 mb-1">SMTP Username</label>
                                        <input type="text" name="smtp_username" value="{{ $settings['smtp_username'] }}" placeholder="your-email@domain.com or api" class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3 focus:ring-blue-500 focus:border-blue-500">
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-semibold text-gray-700 mb-1">SMTP Password / App Password</label>
                                        <input type="password" name="smtp_password" value="{{ $settings['smtp_password'] }}" placeholder="••••••••••••" class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3 focus:ring-blue-500 focus:border-blue-500">
                                    </div>
                                </div>
                            </div>

                            <div class="pt-2 border-t border-gray-100">
                                <h3 class="text-xs font-bold text-gray-900 mb-3 uppercase tracking-wider text-gray-500">3. Sender Identity</h3>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-[11px] font-semibold text-gray-700 mb-1">Sender Name</label>
                                        <input type="text" name="mail_from_name" value="{{ $settings['mail_from_name'] }}" placeholder="Hamnet Platform" class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3 focus:ring-blue-500 focus:border-blue-500">
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-semibold text-gray-700 mb-1">Sender Email</label>
                                        <input type="email" name="mail_from_address" value="{{ $settings['mail_from_address'] }}" placeholder="notifications@yourdomain.com" class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3 focus:ring-blue-500 focus:border-blue-500">
                                    </div>
                                </div>
                            </div>

                            <div class="flex justify-end pt-2">
                                <button type="submit" class="w-full bg-gray-900 hover:bg-gray-800 text-white px-4 py-2.5 rounded-xl text-xs font-semibold shadow-sm transition-colors">
                                    Save Email Settings
                                </button>
                            </div>
                        </form>

                        <!-- Live SMTP Test Card -->
                        <div class="bg-blue-50/70 border border-blue-200/80 rounded-2xl p-4 space-y-3">
                            <div class="flex items-center gap-2 text-blue-900">
                                <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                </svg>
                                <span class="text-xs font-bold">Verify & Send Live Test Email</span>
                            </div>
                            <p class="text-[11px] text-blue-700/90 leading-relaxed">
                                Enter a recipient email to immediately test your SMTP connection and dispatch a real verification message.
                            </p>
                            <form method="POST" action="{{ route('platform.configuration.email.test') }}" class="flex gap-2">
                                @csrf
                                <input type="email" name="test_email" required value="{{ auth()->user()->email }}" placeholder="recipient@example.com" class="flex-1 rounded-xl border-blue-200 text-xs py-2 px-3 focus:ring-blue-500 focus:border-blue-500 bg-white">
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl text-xs font-semibold shadow-sm shrink-0 transition-colors">
                                    Send Test Email
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="px-6 py-4 border-t border-gray-200 bg-gray-50 flex justify-between items-center">
                        <p class="text-[10px] text-gray-500 flex items-center gap-1">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                            Encrypted at rest
                        </p>
                        <button type="button" @click="closeOverlay()" class="px-4 py-2 text-xs font-semibold text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50 transition-colors">Done</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ─── SMS GATEWAY OVERLAY ─── -->
        <div x-show="activeOverlay === 'sms'" class="fixed inset-0 z-50 overflow-hidden" x-cloak>
            <div x-show="activeOverlay === 'sms'" x-transition class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="closeOverlay()"></div>
            <div class="absolute inset-y-0 right-0 max-w-full flex pl-10">
                <div x-show="activeOverlay === 'sms'" 
                     x-data="{ provider: '{{ $settings['sms_provider'] }}' }"
                     x-transition:enter="transform transition ease-in-out duration-500" 
                     x-transition:enter-start="translate-x-full" 
                     x-transition:enter-end="translate-x-0" 
                     x-transition:leave="transform transition ease-in-out duration-300" 
                     x-transition:leave-start="translate-x-0" 
                     x-transition:leave-end="translate-x-full" 
                     class="w-screen max-w-lg bg-white shadow-2xl flex flex-col">
                    <div class="px-6 py-5 border-b border-gray-200 flex justify-between items-center bg-white sticky top-0 z-10">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 bg-purple-500/10 rounded-xl flex items-center justify-center text-purple-600 shrink-0">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
                            </div>
                            <div>
                                <h2 class="text-sm font-bold text-gray-900">SMS Gateway Configuration</h2>
                                <p class="text-[11px] text-gray-500">Africa's Talking & Twilio Gateway Integration</p>
                            </div>
                        </div>
                        <button @click="closeOverlay()" class="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    
                    <div class="flex-1 overflow-y-auto p-6 space-y-6">
                        <!-- Status Banner -->
                        <div class="p-3.5 rounded-xl text-xs flex items-center justify-between {{ str_contains($settings['sms_status'], 'Connected') ? 'bg-emerald-50 border border-emerald-200 text-emerald-800' : (str_contains($settings['sms_status'], 'Failed') ? 'bg-red-50 border border-red-200 text-red-800' : 'bg-purple-50 border border-purple-200/80 text-purple-800') }}">
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full {{ str_contains($settings['sms_status'], 'Connected') ? 'bg-emerald-500' : (str_contains($settings['sms_status'], 'Failed') ? 'bg-red-500' : 'bg-purple-500') }}"></span>
                                <span class="font-bold">Status: {{ $settings['sms_status'] }}</span>
                            </div>
                            <span class="text-[11px] opacity-80">E.164 Auto-formatting</span>
                        </div>

                        <!-- Credentials Form -->
                        <form id="sms-settings-form" method="POST" action="{{ route('platform.configuration.sms.update') }}" class="space-y-4">
                            @csrf
                            @method('PUT')

                            <div>
                                <label class="block text-[11px] font-semibold text-gray-700 mb-1">SMS Service Provider</label>
                                <select name="sms_provider" x-model="provider" class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3 bg-white focus:ring-purple-500 focus:border-purple-500">
                                    <option value="africastalking">Africa's Talking (Uganda & East Africa Default)</option>
                                    <option value="twilio">Twilio (Global)</option>
                                </select>
                            </div>

                            <!-- Africa's Talking Specific Fields -->
                            <div x-show="provider === 'africastalking'" class="space-y-3 pt-2 border-t border-gray-100">
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-[11px] font-semibold text-gray-700 mb-1">Environment</label>
                                        <select name="sms_environment" class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3 bg-white focus:ring-purple-500 focus:border-purple-500">
                                            <option value="sandbox" {{ $settings['sms_environment'] === 'sandbox' ? 'selected' : '' }}>Sandbox</option>
                                            <option value="production" {{ $settings['sms_environment'] === 'production' ? 'selected' : '' }}>Production (Live)</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-semibold text-gray-700 mb-1">AT Username</label>
                                        <input type="text" name="sms_username" value="{{ $settings['sms_username'] }}" placeholder="sandbox or account_username" class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3 focus:ring-purple-500 focus:border-purple-500">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-[11px] font-semibold text-gray-700 mb-1">Africa's Talking API Key</label>
                                    <input type="password" name="sms_api_key" value="{{ $settings['sms_api_key'] }}" placeholder="at_api_key_..." class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3 focus:ring-purple-500 focus:border-purple-500">
                                </div>
                                <div>
                                    <label class="block text-[11px] font-semibold text-gray-700 mb-1">Sender ID / Shortcode (Optional in Sandbox)</label>
                                    <input type="text" name="sms_sender_id" value="{{ $settings['sms_sender_id'] }}" placeholder="HAMNET" class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3 focus:ring-purple-500 focus:border-purple-500">
                                </div>
                            </div>

                            <!-- Twilio Specific Fields -->
                            <div x-show="provider === 'twilio'" class="space-y-3 pt-2 border-t border-gray-100" style="display: none;">
                                <div>
                                    <label class="block text-[11px] font-semibold text-gray-700 mb-1">Twilio Account SID</label>
                                    <input type="text" name="sms_username" value="{{ $settings['sms_username'] }}" placeholder="ACXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX" class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3 focus:ring-purple-500 focus:border-purple-500">
                                </div>
                                <div>
                                    <label class="block text-[11px] font-semibold text-gray-700 mb-1">Twilio Auth Token</label>
                                    <input type="password" name="sms_api_key" value="{{ $settings['sms_api_key'] }}" placeholder="your_auth_token" class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3 focus:ring-purple-500 focus:border-purple-500">
                                </div>
                                <div>
                                    <label class="block text-[11px] font-semibold text-gray-700 mb-1">From Phone Number or Messaging Service SID</label>
                                    <input type="text" name="sms_sender_id" value="{{ $settings['sms_sender_id'] }}" placeholder="+1234567890" class="block w-full rounded-xl border-gray-300 text-xs py-2 px-3 focus:ring-purple-500 focus:border-purple-500">
                                </div>
                            </div>

                            <div class="flex justify-end pt-2">
                                <button type="submit" class="w-full bg-gray-900 hover:bg-gray-800 text-white px-4 py-2.5 rounded-xl text-xs font-semibold shadow-sm transition-colors">
                                    Save SMS Settings
                                </button>
                            </div>
                        </form>

                        <!-- Live SMS Test Card -->
                        <div class="bg-purple-50/70 border border-purple-200/80 rounded-2xl p-4 space-y-3">
                            <div class="flex items-center gap-2 text-purple-900">
                                <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                </svg>
                                <span class="text-xs font-bold">Verify & Send Live Test SMS</span>
                            </div>
                            <p class="text-[11px] text-purple-700/90 leading-relaxed">
                                Enter your phone number (e.g. <span class="font-mono">0772123456</span> or <span class="font-mono">+256772123456</span>). The system will normalize to international E.164 and dispatch a live test voucher message.
                            </p>
                            <form method="POST" action="{{ route('platform.configuration.sms.test') }}" class="flex gap-2">
                                @csrf
                                <input type="text" name="test_phone" required placeholder="+256 7XX XXXXXX" class="flex-1 rounded-xl border-purple-200 text-xs py-2 px-3 focus:ring-purple-500 focus:border-purple-500 bg-white font-mono">
                                <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-xl text-xs font-semibold shadow-sm shrink-0 transition-colors">
                                    Send Test SMS
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="px-6 py-4 border-t border-gray-200 bg-gray-50 flex justify-between items-center">
                        <p class="text-[10px] text-gray-500 flex items-center gap-1">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                            Encrypted at rest
                        </p>
                        <button type="button" @click="closeOverlay()" class="px-4 py-2 text-xs font-semibold text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50 transition-colors">Done</button>
                    </div>
                </div>
            </div>
        </div>
        <!-- ─── END OVERLAYS ─── -->

    </div>
</x-app-layout>
