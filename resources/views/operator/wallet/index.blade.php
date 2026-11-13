<x-app-layout>
    <div class="py-8 bg-gray-50 min-h-screen" x-data="{ 
        showWithdraw: false, 
        step: 1,
        amount: '',
        phone: '{{ $defaultPhone }}',
        password: '',
        minWithdrawal: {{ $minWithdrawal }},
        resetForm() { this.step = 1; this.amount = ''; this.password = ''; this.showWithdraw = false; }
    }">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            
            <!-- Header -->
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Wallet & Mobile Money</h1>
                    <p class="text-gray-500 text-sm mt-1">Manage your earnings, platform fees, and withdrawals.</p>
                </div>
                <div class="flex gap-3">
                    <button type="button" onclick="window.print()" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium shadow-sm hover:bg-gray-50 flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                        Export Statement
                    </button>
                </div>
            </div>

            @if(session('status'))
                <div class="mb-6 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-xl flex items-center gap-3">
                    <svg class="w-5 h-5 text-green-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span class="text-sm font-medium">{{ session('status') }}</span>
                </div>
            @endif

            @if($errors->any())
                <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl">
                    <p class="text-sm font-medium">{{ $errors->first() }}</p>
                </div>
            @endif

            <!-- Top Section: Balance & KPIs -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                
                <!-- Hero Balance Card -->
                <div class="lg:col-span-1 bg-gray-900 rounded-2xl p-6 text-white shadow-xl relative overflow-hidden flex flex-col justify-between">
                    <div class="absolute -right-10 -top-10 w-40 h-40 bg-blue-500 rounded-full opacity-20 blur-2xl"></div>
                    
                    <div>
                        <p class="text-gray-400 text-sm font-medium">Available Balance</p>
                        <h2 class="text-4xl font-extrabold mt-2 tracking-tight">{{ $currency }} {{ number_format((float) ($tenant->wallet_balance ?? 0)) }}</h2>
                        <p class="text-gray-400 text-xs mt-2 flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-green-500"></span> Ready for withdrawal
                        </p>
                    </div>
                    
                    <div class="mt-8">
                        <button type="button" @click="showWithdraw = true; amount = {{ (float) ($tenant->wallet_balance ?? 0) }}" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-xl shadow-lg transition-colors flex items-center justify-center gap-2 active:scale-[0.99]">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path></svg>
                            Withdraw to Mobile Money
                        </button>
                        <p class="text-center text-xs text-gray-500 mt-3">Minimum withdrawal: {{ $currency }} {{ number_format($minWithdrawal) }}</p>
                    </div>
                </div>

                <!-- KPI Cards -->
                <div class="lg:col-span-2 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <!-- Total Gross Sales -->
                    <div class="bg-blue-600 rounded-xl p-5 text-white shadow-sm flex flex-col justify-between">
                        <div class="flex justify-between items-start">
                            <p class="text-sm font-medium text-blue-100">Total Gross Sales</p>
                            <div class="p-2 bg-white/10 rounded-lg">
                                <svg class="w-5 h-5 text-blue-100" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            </div>
                        </div>
                        <p class="text-2xl font-bold mt-3">{{ $currency }} {{ number_format((float) $totalGross) }}</p>
                    </div>

                    <!-- Platform Fees Paid -->
                    <div class="bg-orange-500 rounded-xl p-5 text-white shadow-sm flex flex-col justify-between">
                        <div class="flex justify-between items-start">
                            <p class="text-sm font-medium text-orange-100">Platform Fees ({{ $commissionRate }}%)</p>
                            <div class="p-2 bg-white/10 rounded-lg">
                                <svg class="w-5 h-5 text-orange-100" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4M8 16H4m16 0h-4m-8-4h4m4 0h4"></path></svg>
                            </div>
                        </div>
                        <p class="text-2xl font-bold mt-3">{{ $currency }} {{ number_format((float) $totalFees) }}</p>
                    </div>

                    <!-- Total Withdrawn -->
                    <div class="bg-purple-600 rounded-xl p-5 text-white shadow-sm flex flex-col justify-between">
                        <div class="flex justify-between items-start">
                            <p class="text-sm font-medium text-blue-100">Total Withdrawn</p>
                            <div class="p-2 bg-white/10 rounded-lg">
                                <svg class="w-5 h-5 text-blue-100" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path></svg>
                            </div>
                        </div>
                        <p class="text-2xl font-bold mt-3">{{ $currency }} {{ number_format((float) $totalWithdrawn) }}</p>
                    </div>

                    <!-- Pending Payouts -->
                    <div class="bg-emerald-600 rounded-xl p-5 text-white shadow-sm flex flex-col justify-between">
                        <div class="flex justify-between items-start">
                            <p class="text-sm font-medium text-emerald-100">Pending Payouts</p>
                            <div class="p-2 bg-white/10 rounded-lg">
                                <svg class="w-5 h-5 text-emerald-100" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            </div>
                        </div>
                        <p class="text-2xl font-bold mt-3">{{ $currency }} {{ number_format((float) $pendingPayouts) }}</p>
                    </div>
                </div>
            </div>

            <!-- Transaction Ledger -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-bold text-gray-900">Transaction History</h2>
                        <p class="text-xs text-gray-500 mt-0.5">Complete ledger of sales, platform commissions, and withdrawals.</p>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Date & Time</th>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Description</th>
                                <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">Gross Amount</th>
                                <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">Fee Deducted</th>
                                <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">Net Amount</th>
                                <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            @forelse($transactions as $tx)
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">{{ $tx->created_at->format($dateFormat) }}</div>
                                        <div class="text-xs text-gray-500">{{ $tx->created_at->format('H:i') }} ({{ $timezone }})</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center gap-2.5">
                                            <div class="p-2 rounded-full {{ $tx->type === 'withdrawal' ? 'bg-orange-100 text-orange-600' : 'bg-blue-100 text-blue-600' }}">
                                                @if($tx->type === 'withdrawal')
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path></svg>
                                                @else
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"></path></svg>
                                                @endif
                                            </div>
                                            <div>
                                                <div class="text-sm font-bold text-gray-900 capitalize">{{ str_replace('_', ' ', $tx->type) }}</div>
                                                <div class="text-xs text-gray-500 font-mono">{{ $tx->description ?? "Ref: {$tx->reference}" }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900 font-medium">{{ $currency }} {{ number_format((float) $tx->amount) }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-red-500">
                                        @if($tx->platform_fee > 0)
                                            - {{ $currency }} {{ number_format((float) $tx->platform_fee) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold {{ $tx->net_amount < 0 ? 'text-red-600' : 'text-green-600' }}">
                                        {{ $tx->net_amount < 0 ? '-' : '+' }} {{ $currency }} {{ number_format(abs((float) $tx->net_amount)) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold 
                                            {{ $tx->status === 'completed' ? 'bg-green-100 text-green-800' : '' }}
                                            {{ $tx->status === 'pending' ? 'bg-yellow-100 text-yellow-800' : '' }}
                                            {{ $tx->status === 'failed' ? 'bg-red-100 text-red-800' : '' }}">
                                            {{ ucfirst($tx->status) }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-6 py-12 text-center text-gray-500 text-sm">
                                        <div class="flex flex-col items-center justify-center">
                                            <div class="w-12 h-12 bg-gray-100 rounded-full flex items-center justify-center mb-3 text-gray-400">
                                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                            </div>
                                            <p class="font-medium text-gray-700">No transactions recorded yet</p>
                                            <p class="text-xs text-gray-400 mt-1">Transactions will appear as customers purchase vouchers or request payouts.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($transactions->hasPages())
                    <div class="px-6 py-4 border-t border-gray-200 bg-gray-50">
                        {{ $transactions->links() }}
                    </div>
                @endif
            </div>
        </div>

        <!-- ─── SIDE OVERLAY (Withdrawal) ─── -->
        <div x-show="showWithdraw" class="relative z-50" x-cloak>
            <div x-show="showWithdraw" x-transition.opacity class="fixed inset-0 bg-gray-900/50 backdrop-blur-sm" @click="resetForm()"></div>
            <div class="fixed inset-0 overflow-hidden">
                <div class="absolute inset-0 overflow-hidden">
                    <div class="pointer-events-none fixed inset-y-0 right-0 flex max-w-full pl-10">
                        <div x-show="showWithdraw" x-transition:enter="transform transition ease-in-out duration-300" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transform transition ease-in-out duration-300" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full" class="pointer-events-auto w-screen max-w-md">
                            <div class="flex h-full flex-col overflow-y-scroll bg-white shadow-xl">
                                
                                <!-- Header -->
                                <div class="px-6 py-6 bg-gray-50 border-b border-gray-200 sticky top-0 z-10 flex justify-between items-center">
                                    <div>
                                        <h2 class="text-lg font-bold text-gray-900">Withdraw Funds</h2>
                                        <p class="text-xs text-gray-500 mt-0.5">Transfer balance to Mobile Money</p>
                                    </div>
                                    <button type="button" @click="resetForm()" class="text-gray-400 hover:text-gray-600 rounded-lg p-1">✕</button>
                                </div>

                                <!-- Step 1: Amount & Phone -->
                                <div x-show="step === 1" class="flex-1 px-6 py-6 flex flex-col justify-between">
                                    <div>
                                        <p class="text-sm text-gray-600 mb-6">Enter the amount you wish to withdraw to your registered Mobile Money line.</p>
                                        
                                        <div class="space-y-4">
                                            <div>
                                                <label class="block text-xs font-semibold text-gray-700 uppercase mb-1">Amount ({{ $currency }})</label>
                                                <input type="number" 
                                                       x-model="amount" 
                                                       step="1"
                                                       :min="minWithdrawal"
                                                       max="{{ (float) ($tenant->wallet_balance ?? 0) }}"
                                                       class="block w-full rounded-xl border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-xl font-bold font-mono">
                                                <div class="flex justify-between items-center mt-1.5 text-xs text-gray-500">
                                                    <span>Min: {{ $currency }} {{ number_format($minWithdrawal) }}</span>
                                                    <span>Max: {{ $currency }} {{ number_format((float) ($tenant->wallet_balance ?? 0)) }}</span>
                                                </div>
                                            </div>
                                            <div>
                                                <label class="block text-xs font-semibold text-gray-700 uppercase mb-1">Mobile Money Number</label>
                                                <input type="text" 
                                                       x-model="phone" 
                                                       placeholder="+256700000000"
                                                       class="block w-full rounded-xl border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 font-mono">
                                                <p class="text-xs text-gray-500 mt-1">Enter a whole {{ $currency }} amount. Payouts are sent through Flutterwave to the detected mobile money network.</p>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="pt-8 flex gap-3">
                                        <button type="button" @click="resetForm()" class="flex-1 bg-white border border-gray-300 text-gray-700 py-2.5 rounded-xl text-sm font-semibold hover:bg-gray-50 transition-colors">Cancel</button>
                                        <button type="button" 
                                                @click="if (amount >= minWithdrawal && phone.length >= 9) { step = 2; } else { window.toast.error('Please enter at least {{ $currency }} ' + minWithdrawal.toLocaleString() + ' and a valid phone number.'); }"
                                                class="flex-1 bg-blue-600 text-white py-2.5 rounded-xl text-sm font-semibold hover:bg-blue-700 transition-colors shadow-sm">
                                            Next Step &rarr;
                                        </button>
                                    </div>
                                </div>

                                <!-- Step 2: Password & Consent -->
                                <div x-show="step === 2" class="flex-1 px-6 py-6 flex flex-col justify-between" x-cloak>
                                    <div>
                                        <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-6">
                                            <p class="text-xs font-bold text-blue-900 uppercase tracking-wider">Withdrawal Summary</p>
                                            <div class="flex justify-between mt-3 text-sm text-blue-950">
                                                <span class="text-blue-700">Amount:</span>
                                                <span class="font-bold font-mono">{{ $currency }} <span x-text="Number(amount).toLocaleString()"></span></span>
                                            </div>
                                            <div class="flex justify-between mt-1 text-sm text-blue-950">
                                                <span class="text-blue-700">Recipient Phone:</span>
                                                <span class="font-bold font-mono" x-text="phone"></span>
                                            </div>
                                        </div>

                                        <form method="POST" id="withdrawForm" action="{{ route('operator.wallet.withdraw', ['tenant' => $tenant->slug], false) }}">
                                            @csrf
                                            <input type="hidden" name="amount" :value="amount">
                                            <input type="hidden" name="phone" :value="phone">
                                            
                                            <div class="space-y-4">
                                                <div>
                                                    <label class="block text-xs font-semibold text-gray-700 uppercase mb-1">Confirm Account Password</label>
                                                    <input type="password" name="password" required class="block w-full rounded-xl border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="••••••••">
                                                    <p class="text-xs text-gray-500 mt-1">Required to authorize and release funds.</p>
                                                </div>
                                                
                                                <div class="flex items-start gap-2.5 mt-5 bg-amber-50 border border-amber-200 rounded-xl p-3">
                                                    <input type="checkbox" required id="consentCheck" class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                                    <label for="consentCheck" class="text-xs text-amber-900 leading-relaxed cursor-pointer">
                                                        I confirm this withdrawal is authorized. Once submitted, funds will be transferred to the specified Mobile Money line.
                                                    </label>
                                                </div>
                                            </div>

                                            <div class="pt-8 flex gap-3">
                                                <button type="button" @click="step = 1" class="flex-1 bg-white border border-gray-300 text-gray-700 py-2.5 rounded-xl text-sm font-semibold hover:bg-gray-50 transition-colors">&larr; Back</button>
                                                <button type="submit" class="flex-1 bg-green-600 text-white py-2.5 rounded-xl text-sm font-semibold hover:bg-green-700 transition-colors shadow-sm flex items-center justify-center gap-2">
                                                    Authorize & Payout
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- ─── END SIDE OVERLAY ─── -->

    </div>
</x-app-layout>
