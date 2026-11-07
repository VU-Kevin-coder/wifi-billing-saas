<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Platform Policies</h2>
    </x-slot>

    <div class="py-8 bg-gray-50 min-h-screen" x-data="{ tab: 'lifecycle' }">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            @if(session('status'))
                <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">{{ session('status') }}</div>
            @endif

            <!-- Navigation Tabs (General & Integrations / Policies) -->
            <div class="border-b border-gray-200 mb-6">
                <nav class="-mb-px flex space-x-8">
                    <a href="{{ route('platform.configuration.index') }}"
                       class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center gap-2
                       {{ request()->routeIs('platform.configuration.*') ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                        General & Integrations
                    </a>
                    <a href="{{ route('platform.policies.index') }}"
                       class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center gap-2
                       {{ request()->routeIs('platform.policies.*') ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                        Policies
                    </a>
                </nav>
            </div>

            <!-- Sub-tabs -->
            <div class="border-b border-gray-200 mb-6 overflow-x-auto">
                <nav class="-mb-px flex space-x-6 min-w-max">
                    @foreach([
                        'lifecycle'    => 'Tenant Lifecycle',
                        'security'     => 'Security',
                        'wallet'       => 'Wallet',
                        'provisioning' => 'Provisioning',
                        'voucher'      => 'Vouchers & Scheduling',
                    ] as $key => $label)
                        <button @click="tab = '{{ $key }}'"
                                :class="tab === '{{ $key }}' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                                class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm">
                            {{ $label }}
                        </button>
                    @endforeach
                </nav>
            </div>

            <!-- Lifecycle Tab -->
            <div x-show="tab === 'lifecycle'" class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-bold text-gray-900 mb-4">Tenant Lifecycle</h3>
                <p class="text-sm text-gray-500 mb-6">What each tenant state is allowed to do.</p>

                <form method="POST" action="{{ route('platform.policies.lifecycle.update') }}">
                    @csrf @method('PUT')
                    @foreach($lifecyclePolicies as $state => $items)
                        <div class="mb-6 border-b border-gray-100 pb-4">
                            <h4 class="font-bold text-gray-800 mb-3 capitalize">{{ str_replace('_', ' ', $state) }}</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                @foreach($items as $policy)
                                    @php $action = explode('.', $policy->key)[2]; @endphp
                                    <label class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100">
                                        <input type="hidden" name="policies[{{ $policy->key }}]" value="0">
                                        <input type="checkbox" name="policies[{{ $policy->key }}]" value="1"
                                               {{ $policy->value ? 'checked' : '' }}
                                               class="rounded border-gray-300 text-blue-600">
                                        <span class="text-sm text-gray-700">{{ ucwords(str_replace('_', ' ', $action)) }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                    <div class="flex justify-end">
                        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
                            Save Lifecycle
                        </button>
                    </div>
                </form>
            </div>

            <!-- Security Tab -->
            <div x-show="tab === 'security'" class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-bold text-gray-900 mb-4">Security</h3>
                <form method="POST" action="{{ route('platform.policies.category.update', 'security') }}">
                    @csrf @method('PUT')
                    <div class="space-y-4">
                        @foreach($categories['security'] as $policy)
                            @include('platform.configuration.partials.policy-field', ['policy' => $policy])
                        @endforeach
                    </div>
                    <div class="flex justify-end mt-6">
                        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">Save Security</button>
                    </div>
                </form>
            </div>

            <!-- Wallet Tab -->
            <div x-show="tab === 'wallet'" class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-bold text-gray-900 mb-4">Wallet</h3>
                <form method="POST" action="{{ route('platform.policies.category.update', 'wallet') }}">
                    @csrf @method('PUT')
                    <div class="space-y-4">
                        @foreach($categories['wallet'] as $policy)
                            @include('platform.configuration.partials.policy-field', ['policy' => $policy])
                        @endforeach
                    </div>
                    <div class="flex justify-end mt-6">
                        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">Save Wallet</button>
                    </div>
                </form>

                @if(isset($approval) && $approval->isNotEmpty())
                    <hr class="my-8 border-gray-200">
                    <!-- Approval policy (Batch B preparation) -->
                    <form method="POST" action="{{ route('platform.policies.approval.update') }}">
                        @csrf @method('PUT')
                        <h4 class="text-base font-bold text-gray-900 mb-2">Wallet Adjustment Approval</h4>
                        <p class="text-xs text-gray-500 mb-4">Controls who must approve manual wallet credits/debits.</p>
                        <div class="space-y-4">
                            <label class="flex items-center gap-3">
                                <input type="hidden" name="enabled" value="0">
                                <input type="checkbox" name="enabled" value="1"
                                       {{ ($approval['approval.wallet_adjustment.enabled']->value ?? false) ? 'checked' : '' }}
                                       class="rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500">
                                <span class="text-sm font-medium text-gray-900">Require approval for wallet adjustments</span>
                            </label>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Mode</label>
                                <select name="mode" class="block w-full rounded-md border-gray-300 shadow-sm">
                                    <option value="always" @selected(($approval['approval.wallet_adjustment.mode']->value ?? '') === 'always')>Always require approval</option>
                                    <option value="threshold" @selected(($approval['approval.wallet_adjustment.mode']->value ?? '') === 'threshold')>Only above a threshold</option>
                                    <option value="never" @selected(($approval['approval.wallet_adjustment.mode']->value ?? '') === 'never')>Never require approval</option>
                                </select>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Threshold (UGX)</label>
                                    <input type="number" name="threshold" step="0.01" min="0"
                                           value="{{ $approval['approval.wallet_adjustment.threshold']->value ?? 0 }}"
                                           class="block w-full rounded-md border-gray-300 shadow-sm">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Expiry (hours)</label>
                                    <input type="number" name="expiry_hours" min="1" max="168"
                                           value="{{ $approval['approval.wallet_adjustment.expiry_hours']->value ?? 24 }}"
                                           class="block w-full rounded-md border-gray-300 shadow-sm">
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <label class="flex items-center gap-3">
                                    <input type="hidden" name="self_approve" value="0">
                                    <input type="checkbox" name="self_approve" value="1"
                                           {{ ($approval['approval.wallet_adjustment.self_approve']->value ?? false) ? 'checked' : '' }}
                                           class="rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500">
                                    <span class="text-sm text-gray-700">Requester can self-approve</span>
                                </label>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Minimum admins</label>
                                    <input type="number" name="min_admins" min="1" max="10"
                                           value="{{ $approval['approval.wallet_adjustment.min_admins']->value ?? 2 }}"
                                           class="block w-full rounded-md border-gray-300 shadow-sm">
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end mt-6">
                            <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
                                Save Approval Policy
                            </button>
                        </div>
                    </form>
                @endif
            </div>

            <!-- Provisioning Tab -->
            <div x-show="tab === 'provisioning'" class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-bold text-gray-900 mb-4">Provisioning</h3>
                <form method="POST" action="{{ route('platform.policies.category.update', 'provisioning') }}">
                    @csrf @method('PUT')
                    <div class="space-y-4">
                        @foreach($categories['provisioning'] as $policy)
                            @include('platform.configuration.partials.policy-field', ['policy' => $policy])
                        @endforeach
                    </div>
                    <div class="flex justify-end mt-6">
                        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">Save Provisioning</button>
                    </div>
                </form>
            </div>

            <!-- Voucher & Scheduling Tab -->
            <div x-show="tab === 'voucher'" class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-bold text-gray-900 mb-4">Vouchers & Scheduling</h3>
                <p class="text-xs text-orange-600 bg-orange-50 border border-orange-200 p-2 rounded mb-4">
                    ⚠️ Scheduling changes require restarting the scheduler worker.
                </p>
                <form method="POST" action="{{ route('platform.policies.category.update', 'voucher') }}">
                    @csrf @method('PUT')
                    <div class="space-y-4">
                        @foreach(array_merge($categories['voucher']->all(), ($categories['schedule'] ?? collect())->all()) as $policy)
                            @include('platform.configuration.partials.policy-field', ['policy' => $policy])
                        @endforeach
                    </div>
                    <div class="flex justify-end mt-6">
                        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
