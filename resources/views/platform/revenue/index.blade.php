<x-app-layout>
    <div class="py-8 bg-gray-50 min-h-screen" x-data="{ 
        activeOverlay: null,
        activeOperator: null,
        openOverlay(type, operatorId = null) { this.activeOverlay = type; this.activeOperator = operatorId; },
        closeOverlay() { this.activeOverlay = null; this.activeOperator = null; }
    }">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            
            <!-- Header -->
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Platform Revenue & Earnings</h1>
                    <p class="text-gray-500 text-sm mt-1">Track your SaaS revenue, commissions, and operator payouts.</p>
                </div>
                <div class="flex items-center gap-3">
                    <a href="{{ route('platform.revenue.adjustments.index') }}" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium shadow-sm hover:bg-gray-50">
                        Pending Adjustments ({{ \App\Models\PendingAdjustment::withoutTenancy()->where('status', 'pending')->count() }})
                    </a>
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

            <!-- KPI Section -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                <div class="lg:col-span-1 bg-gray-900 rounded-2xl p-6 text-white shadow-xl relative overflow-hidden flex flex-col justify-between">
                    <div class="absolute -right-10 -top-10 w-40 h-40 bg-green-500 rounded-full opacity-20 blur-2xl"></div>
                    <div>
                        <p class="text-gray-400 text-sm font-medium">Total Platform Earnings</p>
                        <h2 class="text-4xl font-extrabold mt-2 tracking-tight">{{ $currency ?? 'UGX' }} {{ number_format($totalCommissions + $subscriptionRevenue) }}</h2>
                        <p class="text-gray-400 text-xs mt-2">Combined subscription fees & voucher commissions</p>
                    </div>
                </div>

                <div class="lg:col-span-2 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="bg-blue-600 rounded-xl p-5 text-white shadow-sm flex flex-col justify-between">
                        <p class="text-sm font-medium text-blue-100">Subscription Revenue</p>
                        <p class="text-2xl font-bold mt-2">{{ $currency ?? 'UGX' }} {{ number_format($subscriptionRevenue) }}</p>
                    </div>
                    <div class="bg-orange-500 rounded-xl p-5 text-white shadow-sm flex flex-col justify-between">
                        <p class="text-sm font-medium text-orange-100">Voucher Commissions</p>
                        <p class="text-2xl font-bold mt-2">{{ $currency ?? 'UGX' }} {{ number_format($totalCommissions) }}</p>
                    </div>
                    <div class="bg-blue-700 rounded-xl p-5 text-white shadow-sm flex flex-col justify-between">
                        <p class="text-sm font-medium text-blue-100">Operator Payouts (Completed)</p>
                        <p class="text-2xl font-bold mt-2">{{ $currency ?? 'UGX' }} {{ number_format($platformPayouts) }}</p>
                    </div>
                    <div class="bg-emerald-600 rounded-xl p-5 text-white shadow-sm flex flex-col justify-between">
                        <p class="text-sm font-medium text-emerald-100">Pending Payouts</p>
                        <p class="text-2xl font-bold mt-2">{{ $currency ?? 'UGX' }} {{ number_format($operators->sum('pending_withdrawals_amount')) }}</p>
                    </div>
                </div>
            </div>

            <!-- Operator Wallets Table -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="p-6 border-b border-gray-200 flex justify-between items-center">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900">Operator Wallets &amp; Payout Requests</h3>
                        <p class="text-xs text-gray-500 mt-0.5">Approve or reject withdrawal requests and monitor tenant balances.</p>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Operator / KYC</th>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Wallet Balance</th>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Pending Payouts</th>
                                <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            @forelse($operators as $tenant)
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center text-blue-700 font-bold shrink-0">
                                                {{ substr($tenant->name, 0, 1) }}
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-bold text-gray-900">{{ $tenant->name }}</div>
                                                <div class="flex items-center gap-1 mt-0.5">
                                                    @if($tenant->kyc_status === 'verified')
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-green-100 text-green-800">
                                                            ✓ KYC Verified
                                                        </span>
                                                    @else
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-yellow-100 text-yellow-800">
                                                            ⏳ KYC Pending
                                                        </span>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-bold text-gray-900">{{ $currency ?? 'UGX' }} {{ number_format((float) $tenant->wallet_balance) }}</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        @if($tenant->pending_withdrawals_count > 0)
                                            <div class="text-sm font-bold text-yellow-600">{{ $currency ?? 'UGX' }} {{ number_format((float) $tenant->pending_withdrawals_amount) }}</div>
                                            <button type="button" @click="openOverlay('review', {{ $tenant->id }})" class="inline-flex items-center gap-1 text-xs font-semibold text-blue-600 hover:text-blue-800 mt-1">
                                                Review {{ $tenant->pending_withdrawals_count }} request(s) &rarr;
                                            </button>
                                        @else
                                            <span class="text-sm text-gray-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center">
                                        <div class="flex items-center justify-center gap-2">
                                            @if($tenant->pending_withdrawals_count > 0)
                                                <button type="button" @click="openOverlay('review', {{ $tenant->id }})" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold px-3 py-1.5 rounded-lg shadow-sm transition-colors">
                                                    Review Payout
                                                </button>
                                            @endif
                                            <button type="button" @click="openOverlay('adjust', {{ $tenant->id }})" class="text-gray-700 hover:text-gray-900 text-xs font-semibold bg-gray-100 hover:bg-gray-200 px-3 py-1.5 rounded-lg transition-colors">
                                                Adjust Balance
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-8 text-center text-gray-500 text-sm">No operators registered yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ─── SIDE OVERLAYS ─── -->
        
        <!-- Review Payout Overlay -->
        <div x-show="activeOverlay === 'review'" class="fixed inset-0 z-50 overflow-hidden" x-cloak>
            <div x-show="activeOverlay === 'review'" x-transition.opacity class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="closeOverlay()"></div>
            <div class="absolute inset-y-0 right-0 max-w-full flex pl-10">
                <div x-show="activeOverlay === 'review'" x-transition:enter="transform transition ease-in-out duration-300" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transform transition ease-in-out duration-300" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full" class="w-screen max-w-md bg-white shadow-2xl flex flex-col">
                    <div class="px-6 py-6 border-b border-gray-200 flex justify-between items-center bg-gray-50">
                        <div>
                            <h2 class="text-lg font-bold text-gray-900">Review Withdrawal Requests</h2>
                            <p class="text-xs text-gray-500 mt-0.5">Approve disbursement or reject &amp; refund</p>
                        </div>
                        <button type="button" @click="closeOverlay()" class="text-gray-400 hover:text-gray-600 rounded-lg p-1">✕</button>
                    </div>
                    
                    <div class="flex-1 p-6 overflow-y-auto" x-show="activeOperator">
                        @foreach($operators as $tenant)
                            <template x-if="activeOperator === {{ $tenant->id }}">
                                <div class="space-y-6">
                                    @php $pendingTxs = $pendingWithdrawalsByTenant->get($tenant->id, collect()); @endphp
                                    @forelse($pendingTxs as $pendingTx)
                                        <div class="bg-gray-50 border border-gray-200 rounded-2xl p-5 space-y-3">
                                            <div class="flex justify-between items-start">
                                                <div>
                                                    <p class="text-xs font-semibold text-gray-500 uppercase">Operator</p>
                                                    <p class="text-sm font-bold text-gray-900">{{ $tenant->name }}</p>
                                                </div>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-yellow-100 text-yellow-800">Pending</span>
                                            </div>

                                            <div class="border-t border-gray-200 pt-2 flex justify-between items-center text-sm">
                                                <span class="text-gray-500">Amount:</span>
                                                <span class="font-bold text-base text-gray-900 font-mono">{{ $currency ?? 'UGX' }} {{ number_format((float) $pendingTx->amount) }}</span>
                                            </div>

                                            @if(($pendingTx->meta['payout_method'] ?? null) === 'bank_transfer')
                                                <div class="rounded-lg bg-blue-50 p-3 text-xs">
                                                    <b class="text-blue-900">Bank transfer destination</b>
                                                    @if(is_array($pendingTx->bank_details))
                                                        <div class="mt-1 text-blue-800">Bank: {{ $pendingTx->bank_details['bank_name'] ?? '—' }} · Account name: {{ $pendingTx->bank_details['account_name'] ?? '—' }} · Account number: {{ $pendingTx->bank_details['account_number'] ?? '—' }}</div>
                                                    @else
                                                        <div class="mt-1 text-red-700">Bank details could not be decrypted. Do not approve until they are reviewed.</div>
                                                    @endif
                                                </div>
                                            @else
                                                <div class="flex justify-between items-center text-xs">
                                                    <span class="text-gray-500">{{ str(($pendingTx->meta['payout_method'] ?? 'mobile_money'))->replace('_', ' ')->title() }} destination:</span>
                                                    <span class="font-mono font-bold text-blue-700">{{ $pendingTx->phone ?? '—' }}</span>
                                                </div>
                                            @endif

                                            <div class="flex justify-between items-center text-xs">
                                                <span class="text-gray-500">Reference:</span>
                                                <span class="font-mono text-gray-600">{{ $pendingTx->reference }}</span>
                                            </div>

                                            <div class="flex justify-between items-center text-xs">
                                                <span class="text-gray-500">Requested At:</span>
                                                <span class="text-gray-600">{{ $pendingTx->created_at->format('d M Y, H:i') }}</span>
                                            </div>
                                            
                                            <div class="pt-2 flex gap-3 border-t border-gray-200">
                                                <form method="POST" action="{{ route('platform.revenue.withdrawals.reject', $pendingTx->id) }}" class="flex-1" data-confirm-action="Reject this withdrawal and refund funds to the operator?">
                                                    @csrf
                                                    <button type="submit" class="w-full bg-white border border-red-300 text-red-600 py-2 rounded-xl text-xs font-bold hover:bg-red-50 transition-colors">
                                                        Reject &amp; Refund
                                                    </button>
                                                </form>
                                                <form method="POST" action="{{ route('platform.revenue.withdrawals.approve', $pendingTx->id) }}" class="flex-1" data-confirm-action="{{ ($pendingTx->meta['payout_method'] ?? null) === 'bank_transfer' ? 'Confirm you have completed this bank transfer and approve the withdrawal?' : 'Approve and release funds for this withdrawal?' }}">
                                                    @csrf
                                                    @if(($pendingTx->meta['payout_method'] ?? null) === 'bank_transfer')
                                                        <input type="text" name="payout_reference" required minlength="3" maxlength="100" placeholder="Bank transfer reference" class="mb-2 w-full rounded-lg border border-gray-300 px-2 py-1 text-xs">
                                                    @endif
                                                    <button type="submit" class="w-full bg-green-600 text-white py-2 rounded-xl text-xs font-bold hover:bg-green-700 transition-colors shadow-sm">
                                                        {{ ($pendingTx->meta['payout_method'] ?? null) === 'bank_transfer' ? 'Confirm Bank Transfer' : 'Approve Payout' }}
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    @empty
                                        <p class="text-sm text-gray-500 text-center py-8">No pending withdrawals found for this operator.</p>
                                    @endforelse
                                </div>
                            </template>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <!-- Adjust Balance Overlay -->
        <div x-show="activeOverlay === 'adjust'" class="fixed inset-0 z-50 overflow-hidden" x-cloak>
            <div x-show="activeOverlay === 'adjust'" x-transition.opacity class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="closeOverlay()"></div>
            <div class="absolute inset-y-0 right-0 max-w-full flex pl-10">
                <div x-show="activeOverlay === 'adjust'" x-transition:enter="transform transition ease-in-out duration-300" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transform transition ease-in-out duration-300" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full" class="w-screen max-w-md bg-white shadow-2xl flex flex-col">
                    <div class="px-6 py-6 border-b border-gray-200 flex justify-between items-center bg-gray-50">
                        <div>
                            <h2 class="text-lg font-bold text-gray-900">Adjust Operator Balance</h2>
                            <p class="text-xs text-gray-500 mt-0.5">Credit or debit an operator's wallet directly</p>
                        </div>
                        <button type="button" @click="closeOverlay()" class="text-gray-400 hover:text-gray-600 rounded-lg p-1">✕</button>
                    </div>
                    
                    <div class="flex-1 p-6 overflow-y-auto" x-show="activeOperator">
                        @foreach($operators as $tenant)
                            <template x-if="activeOperator === {{ $tenant->id }}">
                                <form method="POST" action="{{ route('platform.revenue.adjust', $tenant->id) }}" class="flex flex-col justify-between h-full">
                                    @csrf
                                    <div class="space-y-4">
                                        <div class="bg-gray-50 rounded-xl p-4 border border-gray-200">
                                            <p class="text-xs font-semibold text-gray-500 uppercase">Selected Operator</p>
                                            <p class="text-sm font-bold text-gray-900 mt-0.5">{{ $tenant->name }}</p>
                                            <p class="text-xs text-gray-600 mt-1">Current Balance: <span class="font-bold font-mono">{{ $currency ?? 'UGX' }} {{ number_format((float) $tenant->wallet_balance) }}</span></p>
                                        </div>

                                        <div>
                                            <label class="block text-xs font-semibold text-gray-700 uppercase mb-1">Adjustment Amount ({{ $currency ?? 'UGX' }})</label>
                                            <input type="number" step="0.01" name="amount" required min="-{{ config('payments.max_wallet_adjustment') }}" max="{{ config('payments.max_wallet_adjustment') }}" placeholder="e.g. 10000 or -5000" class="block w-full rounded-xl border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 font-mono text-base font-bold">
                                            <p class="text-xs text-gray-500 mt-1">Positive number to credit, negative number (e.g., -5000) to debit. Reason must be at least 10 characters; every adjustment is audit-logged.</p>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-gray-700 uppercase mb-1">Reason / Description</label>
                                            <input type="text" name="reason" required minlength="10" maxlength="255" placeholder="e.g. Router compensation credit / Manual refund" class="block w-full rounded-xl border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm">
                                        </div>
                                    </div>
                                    <div class="pt-8 flex gap-3">
                                        <button type="button" @click="closeOverlay()" class="flex-1 bg-white border border-gray-300 text-gray-700 py-2.5 rounded-xl text-sm font-semibold hover:bg-gray-50 transition-colors">Cancel</button>
                                        <button type="submit" class="flex-1 bg-blue-600 text-white py-2.5 rounded-xl text-sm font-semibold hover:bg-blue-700 transition-colors shadow-sm">Save Adjustment</button>
                                    </div>
                                </form>
                            </template>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

    </div>
</x-app-layout>
