<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Settings</h2>
            @if ($activeTab === 'portal')
                <a href="{{ route('portal.index', ['tenant' => $tenant->slug]) }}" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 shadow-sm transition-colors">
                    View Live Portal
                </a>
            @endif
        </div>
    </x-slot>

    <div class="py-8" x-data="{ hasChanges: false, primaryColor: @js(old('primary_color', $settings['primary_color'])) }">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-gray-900">Settings</h1>
            </div>

            <div class="border-b border-gray-200 mb-8 overflow-x-auto">
                <nav class="-mb-px flex space-x-8 min-w-max" aria-label="Settings">
                    <a href="{{ route('operator.settings.index', ['tenant' => $tenant->slug, 'tab' => 'general']) }}" @if ($activeTab === 'general') aria-current="page" @endif class="{{ $activeTab === 'general' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }} whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">General Settings</a>
                    <a href="{{ route('operator.settings.index', ['tenant' => $tenant->slug, 'tab' => 'hotspot']) }}" @if ($activeTab === 'hotspot') aria-current="page" @endif class="{{ $activeTab === 'hotspot' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }} whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">Hotspot</a>
                    <a href="{{ route('operator.settings.index', ['tenant' => $tenant->slug, 'tab' => 'portal']) }}" @if ($activeTab === 'portal') aria-current="page" @endif class="{{ $activeTab === 'portal' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }} whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">Captive Portal</a>
                    <a href="#domain" class="border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">Domain</a>
                    <a href="{{ route('operator.settings.index', ['tenant' => $tenant->slug, 'tab' => 'sms']) }}" @if ($activeTab === 'sms') aria-current="page" @endif class="{{ $activeTab === 'sms' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }} whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">SMS</a>
                    <a href="{{ route('operator.settings.index', ['tenant' => $tenant->slug, 'tab' => 'whatsapp']) }}" @if ($activeTab === 'whatsapp') aria-current="page" @endif class="{{ $activeTab === 'whatsapp' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }} whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">WhatsApp</a>
                    <a href="{{ route('operator.settings.index', ['tenant' => $tenant->slug, 'tab' => 'notifications']) }}" @if ($activeTab === 'notifications') aria-current="page" @endif class="{{ $activeTab === 'notifications' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }} whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">Notifications</a>
                    <a href="{{ route('operator.settings.index', ['tenant' => $tenant->slug, 'tab' => 'security']) }}" @if ($activeTab === 'security') aria-current="page" @endif class="{{ $activeTab === 'security' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }} whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">Security</a>
                </nav>
            </div>

            @if (session('status'))
                <div class="mb-6 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg" role="status">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg" role="alert">
                    <ul class="list-disc list-inside">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($activeTab === 'portal')
                @include('operator.settings.portal')
            @elseif ($activeTab === 'hotspot')
                @include('operator.settings.hotspot')
            @elseif ($activeTab === 'sms')
                @include('operator.settings.sms')
            @elseif ($activeTab === 'whatsapp')
                @include('operator.settings.whatsapp')
            @elseif ($activeTab === 'notifications')
                @include('operator.settings.notifications')
            @elseif ($activeTab === 'security')
                @include('operator.settings.security')
            @else
            <form method="POST" action="{{ route('operator.settings.general.update', ['tenant' => $tenant->slug]) }}" enctype="multipart/form-data" @change="hasChanges = true">
                @csrf
                @method('PUT')

                <section class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
                    <div class="p-6 border-b border-gray-100">
                        <h3 class="text-lg font-semibold text-gray-900">Your ISP Branding</h3>
                        <p class="text-sm text-gray-500 mt-1">Configure branding and customer support details for {{ $tenant->name }}.</p>
                    </div>

                    <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="logo" class="block text-sm font-medium text-gray-700 mb-2">Your Logo</label>
                            <div class="flex min-h-48 flex-col items-center justify-center px-6 py-5 border-2 border-gray-300 border-dashed rounded-lg bg-gray-50">
                                @if (!empty($settings['logo_path']))
                                    <img src="{{ asset('storage/' . $settings['logo_path']) }}" class="max-h-16 object-contain mb-3" alt="{{ $settings['brand_name'] }} logo">
                                @endif
                                <input id="logo" name="logo" type="file" accept=".jpeg,.jpg,.png,.svg,image/jpeg,image/png" class="block w-full text-sm text-gray-600 file:mr-4 file:rounded-md file:border-0 file:bg-blue-50 file:px-4 file:py-2 file:font-medium file:text-blue-700 hover:file:bg-blue-100">
                                <p class="text-xs text-gray-500 mt-2">PNG, JPG, or SVG up to 2MB.</p>
                            </div>
                            <p class="text-xs text-gray-500 mt-2">Appears on your login page and customer portal.</p>
                            @error('logo') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>

                        <div class="space-y-6">
                            <div>
                                <label for="brand_name" class="block text-sm font-medium text-gray-700">Your ISP / WiFi Business Name <span class="text-red-500">*</span></label>
                                <input id="brand_name" type="text" name="brand_name" value="{{ old('brand_name', $settings['brand_name']) }}" required maxlength="255" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <p class="text-xs text-gray-500 mt-1">This name appears on vouchers, messages, and the captive portal.</p>
                                @error('brand_name') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="primary_color" class="block text-sm font-medium text-gray-700">Brand Color</label>
                                <div class="mt-1 flex items-center gap-3">
                                    <input id="primary_color" type="color" name="primary_color" x-model="primaryColor" class="h-10 w-10 border border-gray-300 rounded cursor-pointer">
                                    <span class="text-sm font-mono text-gray-600" x-text="primaryColor"></span>
                                </div>
                                @error('primary_color') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>

                    <div class="p-6 border-t border-gray-100">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Customer Support</h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label for="support_phone" class="block text-sm font-medium text-gray-700">Primary Support Number</label>
                                <input id="support_phone" type="text" name="support_phone" value="{{ old('support_phone', $settings['support_phone']) }}" maxlength="20" placeholder="+256700000000" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                @error('support_phone') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="whatsapp_number" class="block text-sm font-medium text-gray-700">WhatsApp Support Number</label>
                                <input id="whatsapp_number" type="text" name="whatsapp_number" value="{{ old('whatsapp_number', $settings['whatsapp_number']) }}" maxlength="20" placeholder="+256700000000" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                @error('whatsapp_number') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="support_email" class="block text-sm font-medium text-gray-700">Customer Support Email</label>
                                <input id="support_email" type="email" name="support_email" value="{{ old('support_email', $settings['support_email']) }}" maxlength="255" placeholder="support@example.com" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                @error('support_email') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>
                </section>

                <section id="domain" class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-gray-900">Domain</h3>
                        <p class="text-sm text-gray-500 mt-1">Your portal address is used for your WiFi portal and self-service pages.</p>
                        <div class="mt-4 flex max-w-2xl rounded-md shadow-sm">
                            <input id="domain" x-ref="domain" type="text" name="domain" value="{{ old('domain', $settings['domain']) }}" readonly class="flex-1 rounded-l-md border-gray-300 bg-gray-50 focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                            <button type="button" @click="navigator.clipboard.writeText($refs.domain.value)" class="inline-flex items-center px-4 rounded-r-md border border-l-0 border-gray-300 bg-gray-50 text-gray-600 sm:text-sm hover:bg-gray-100">Copy</button>
                        </div>
                        @error('domain') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                </section>

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
            @endif
        </div>
    </div>
</x-app-layout>
