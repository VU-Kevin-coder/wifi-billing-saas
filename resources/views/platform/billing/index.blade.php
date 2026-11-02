<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-2 text-sm text-gray-500">
            <a href="{{ route('platform.tenants.index') }}" class="hover:text-gray-900">Platform administration</a>
            <span>&rsaquo;</span>
            <span class="text-gray-900 font-medium">Platform billing</span>
        </div>
    </x-slot>

    <div class="py-8 bg-gray-50 min-h-screen" x-data="{ 
        showPlanModal: {{ $errors->any() ? 'true' : 'false' }},
        isEdit: false,
        editPlanId: null,
        planForm: {
            name: '{{ old('name', '') }}',
            price: {{ old('price', 180000) }},
            trial_days: {{ old('trial_days', 14) }},
            max_customers: {{ old('max_customers', 2000) }},
            max_routers: {{ old('max_routers', 10) }},
            features: `{{ old('features', '') }}`
        },
        openCreatePlan() {
            this.isEdit = false;
            this.editPlanId = null;
            this.planForm = {
                name: '',
                price: 180000,
                trial_days: 14,
                max_customers: 2000,
                max_routers: 10,
                features: ''
            };
            this.showPlanModal = true;
        },
        openEditPlan(plan) {
            this.isEdit = true;
            this.editPlanId = plan.id;
            this.planForm = {
                name: plan.name || '',
                price: plan.price || 0,
                trial_days: plan.trial_days || 14,
                max_customers: plan.max_customers || 2000,
                max_routers: plan.max_routers || 10,
                features: plan.features || ''
            };
            this.showPlanModal = true;
        }
    }">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            
            <!-- Flash Notifications -->
            @if(session('success'))
                <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 rounded-xl flex items-center justify-between shadow-sm">
                    <div class="flex items-center gap-2.5">
                        <svg class="w-5 h-5 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span class="text-sm font-semibold">{{ session('success') }}</span>
                    </div>
                    <button type="button" @click="$el.closest('div').remove()" class="text-emerald-600 hover:text-emerald-800 text-lg leading-none font-bold px-1">&times;</button>
                </div>
            @endif

            @if(session('error'))
                <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-xl flex items-center justify-between shadow-sm">
                    <div class="flex items-center gap-2.5">
                        <svg class="w-5 h-5 text-red-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        <span class="text-sm font-semibold">{{ session('error') }}</span>
                    </div>
                    <button type="button" @click="$el.closest('div').remove()" class="text-red-600 hover:text-red-800 text-lg leading-none font-bold px-1">&times;</button>
                </div>
            @endif

            <!-- Header -->
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Platform billing & plans</h1>
                    <p class="text-gray-500 text-sm mt-1">Manage operator subscription tiers, track recurring revenue, and oversee operator billing status.</p>
                </div>
                <div class="flex gap-2.5">
                    <button @click="openCreatePlan()" type="button" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium shadow-sm flex items-center gap-2 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                        Create subscription plan
                    </button>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <p class="text-xs font-bold uppercase tracking-wider text-gray-400">Monthly recurring revenue</p>
                    <p class="text-2xl font-bold text-gray-900 mt-2">UGX {{ number_format($monthlyRecurring) }}</p>
                    <p class="text-xs text-green-600 mt-1 font-medium">{{ $activeCount }} paid active {{ Str::plural('operator', $activeCount) }}</p>
                </div>
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <p class="text-xs font-bold uppercase tracking-wider text-gray-400">Trial pipeline</p>
                    <p class="text-2xl font-bold text-gray-900 mt-2">UGX {{ number_format($trialPipelineValue) }}</p>
                    <p class="text-xs text-blue-600 mt-1 font-medium">{{ $trialCount }} in 14-day free trial</p>
                </div>
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <p class="text-xs font-bold uppercase tracking-wider text-gray-400">Overdue fees</p>
                    <p class="text-2xl font-bold {{ $overdueFees > 0 ? 'text-red-600' : 'text-gray-900' }} mt-2">UGX {{ number_format($overdueFees) }}</p>
                    <p class="text-xs text-gray-500 mt-1">
                        @if($overdueCount > 0)
                            <span class="text-red-500 font-medium">{{ $overdueCount }} {{ Str::plural('operator', $overdueCount) }} past due</span>
                        @else
                            All accounts up to date
                        @endif
                    </p>
                </div>
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <p class="text-xs font-bold uppercase tracking-wider text-gray-400">Platform plans</p>
                    <p class="text-2xl font-bold text-gray-900 mt-2">{{ $plans->count() }} active tiers</p>
                    <p class="text-xs text-gray-500 mt-1">{{ $invoices->total() }} total {{ Str::plural('tenant', $invoices->total()) }} enrolled</p>
                </div>
            </div>

            <!-- Overdue Warning Banner (Real Data) -->
            @if($overdueCount > 0)
            <div class="bg-orange-50 border border-orange-200 rounded-xl p-4 flex items-start gap-3">
                <svg class="w-5 h-5 text-orange-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                <div class="flex-1">
                    <p class="text-sm font-semibold text-orange-900">{{ $overdueCount }} operator {{ Str::plural('invoice', $overdueCount) }} are overdue (UGX {{ number_format($overdueFees) }} total)</p>
                    <div class="text-xs text-orange-700 mt-1 space-x-2">
                        @foreach($pastDueTenants as $pdt)
                            <span class="font-medium">• {{ $pdt->name }} (UGX {{ number_format($pdt->plan_fee) }})</span>
                        @endforeach
                    </div>
                </div>
            </div>
            @endif

            <!-- ─── SECTION 1: SUBSCRIPTION PLANS (MAIN BODY) ─── -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex justify-between items-center mb-4">
                    <div>
                        <h2 class="text-base font-bold text-gray-900">SaaS Subscription Plans</h2>
                        <p class="text-xs text-gray-500 mt-0.5">Tiered packages defining customer capacity, router quotas, and monthly billing rates.</p>
                    </div>
                    <button @click="openCreatePlan()" type="button" class="text-blue-600 hover:text-blue-800 text-xs font-semibold flex items-center gap-1">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                        Add Plan
                    </button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-5 pt-2">
                    @forelse($plans as $plan)
                        <div class="border border-gray-200 rounded-xl p-5 hover:border-blue-300 transition-all bg-gray-50/40 flex flex-col justify-between shadow-xs">
                            <div>
                                <div class="flex justify-between items-start mb-2">
                                    <div>
                                        <h3 class="font-bold text-gray-900 text-base">{{ $plan->name }}</h3>
                                        <p class="text-[11px] text-gray-500">{{ $plan->trial_days }}-day free trial included</p>
                                    </div>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-blue-50 text-blue-700 border border-blue-200/60">
                                        {{ $plan->tenants_count }} {{ Str::plural('operator', $plan->tenants_count) }}
                                    </span>
                                </div>

                                <div class="my-3">
                                    <span class="text-2xl font-bold text-gray-900">UGX {{ number_format($plan->price) }}</span>
                                    <span class="text-xs text-gray-500 font-medium">/ month</span>
                                </div>

                                <div class="border-t border-gray-100 pt-3 space-y-1.5 text-xs text-gray-600">
                                    <div class="flex items-center gap-2">
                                        <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                        <span>Up to <strong>{{ number_format($plan->max_customers) }}</strong> end-customers</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                        <span>Up to <strong>{{ $plan->max_routers }}</strong> MikroTik routers</span>
                                    </div>
                                    @if(is_array($plan->features))
                                        @foreach($plan->features as $feature)
                                            <div class="flex items-center gap-2">
                                                <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                <span>{{ $feature }}</span>
                                            </div>
                                        @endforeach
                                    @endif
                                </div>
                            </div>

                            <!-- Plan Action Buttons: Edit & Delete -->
                            <div class="mt-5 pt-3 border-t border-gray-200/80 flex items-center justify-between">
                                <button type="button" 
                                        @click="openEditPlan({{ json_encode([
                                            'id' => $plan->id,
                                            'name' => $plan->name,
                                            'price' => (float)$plan->price,
                                            'trial_days' => $plan->trial_days,
                                            'max_customers' => $plan->max_customers,
                                            'max_routers' => $plan->max_routers,
                                            'features' => is_array($plan->features) ? implode("\n", $plan->features) : ($plan->features ?? '')
                                        ]) }})"
                                        class="inline-flex items-center gap-1.5 text-xs font-semibold text-blue-600 hover:text-blue-800 bg-blue-50 hover:bg-blue-100 px-3 py-1.5 rounded-lg transition-colors">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    Edit plan
                                </button>

                                <form action="{{ route('platform.plans.destroy', $plan) }}" method="POST" onsubmit="return confirm('Delete plan \'{{ $plan->name }}\'? {{ $plan->tenants_count > 0 ? 'This will unlink ' . $plan->tenants_count . ' operator tenant(s) from this plan.' : '' }}')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" 
                                            class="inline-flex items-center gap-1 text-xs font-semibold text-red-600 hover:text-red-800 hover:bg-red-50 px-2.5 py-1.5 rounded-lg transition-colors"
                                            title="Delete plan">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    @empty
                        <div class="col-span-3 text-center py-8 text-sm text-gray-400">
                            No subscription plans found. Click "+ Add Plan" to define your first tier.
                        </div>
                    @endforelse
                </div>
            </div>

            <!-- ─── SECTION 2: OPERATOR INVOICES & SUBSCRIPTION STATUS ─── -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="p-4 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
                    <div>
                        <h2 class="text-sm font-bold text-gray-900">Operator Subscriptions & Invoices</h2>
                        <p class="text-xs text-gray-500 mt-0.5">Platform subscription status for active operator organizations.</p>
                    </div>
                    <span class="text-xs text-gray-500">{{ $invoices->total() }} total operator {{ Str::plural('record', $invoices->total()) }}</span>
                </div>
                
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-white">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Invoice Ref</th>
                            <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Operator Tenant</th>
                            <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Assigned Plan</th>
                            <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Plan Fee</th>
                            <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Next Renewal / Expiry</th>
                            <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Subscription Status</th>
                            <th class="relative px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">Action</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100">
                        @forelse($invoices as $tenant)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-xs font-bold text-gray-900 font-mono">INV-{{ date('Y') }}-{{ str_pad($tenant->id, 4, '0', STR_PAD_LEFT) }}</div>
                                    <div class="text-[11px] text-gray-400">{{ $tenant->created_at->format('M Y') }} enrollment</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <a href="{{ route('platform.tenants.show', $tenant) }}" class="text-sm font-bold text-blue-600 hover:underline block">
                                        {{ $tenant->name }}
                                    </a>
                                    <div class="text-[11px] text-gray-400 font-mono">{{ $tenant->slug }}.localhost</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-gray-100 text-gray-800">
                                        {{ is_string($tenant->plan) ? $tenant->plan : ($tenant->plan->name ?? 'Growth') }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">
                                    UGX {{ number_format($tenant->plan_fee ?? 0) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-xs text-gray-700">
                                    @if($tenant->subscription_ends_at)
                                        <div>{{ $tenant->subscription_ends_at->format('d M Y') }}</div>
                                        <div class="text-[11px] text-gray-400">{{ $tenant->subscription_ends_at->diffForHumans() }}</div>
                                    @elseif($tenant->trial_ends_at)
                                        <div>{{ $tenant->trial_ends_at->format('d M Y') }}</div>
                                        <div class="text-[11px] text-blue-600 font-medium">Trial ends {{ $tenant->trial_ends_at->diffForHumans() }}</div>
                                    @else
                                        <span class="text-gray-400">Ongoing</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if($tenant->subscription_status === 'active')
                                        <span class="inline-flex items-center gap-1 text-xs font-bold text-green-700 bg-green-50 px-2 py-0.5 rounded">
                                            <span class="w-1.5 h-1.5 rounded-full bg-green-600"></span> Active (Paid)
                                        </span>
                                    @elseif($tenant->subscription_status === 'trial')
                                        <span class="inline-flex items-center gap-1 text-xs font-bold text-blue-700 bg-blue-50 px-2 py-0.5 rounded">
                                            <span class="w-1.5 h-1.5 rounded-full bg-blue-600"></span> 14-Day Trial
                                        </span>
                                    @elseif($tenant->subscription_status === 'past_due')
                                        <span class="inline-flex items-center gap-1 text-xs font-bold text-red-700 bg-red-50 px-2 py-0.5 rounded">
                                            <span class="w-1.5 h-1.5 rounded-full bg-red-600"></span> Overdue
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 text-xs font-bold text-gray-700 bg-gray-100 px-2 py-0.5 rounded">
                                            <span class="w-1.5 h-1.5 rounded-full bg-gray-500"></span> Suspended
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                    <a href="{{ route('platform.impersonate', $tenant) }}" 
                                       class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold text-blue-600 bg-blue-50 hover:bg-blue-100 rounded-lg transition-colors"
                                       title="Access tenant workspace">
                                        <span>Switch into</span>
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-8 text-center text-sm text-gray-400">
                                    No operator billing records found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="px-6 py-4 border-t border-gray-200 bg-gray-50">
                    {{ $invoices->links() }}
                </div>
            </div>
        </div>

        <!-- ─── SLIDE-OVER DRAWER: CREATE / EDIT PLATFORM PLAN ─── -->
        <div x-show="showPlanModal" class="relative z-50" aria-labelledby="slide-over-title" role="dialog" aria-modal="true" x-cloak>
            <div x-show="showPlanModal" 
                 x-transition:enter="ease-in-out duration-300" 
                 x-transition:enter-start="opacity-0" 
                 x-transition:enter-end="opacity-100" 
                 x-transition:leave="ease-in-out duration-300" 
                 x-transition:leave-start="opacity-100" 
                 x-transition:leave-end="opacity-0" 
                 class="fixed inset-0 bg-gray-900/50 backdrop-blur-sm transition-opacity" 
                 @click="showPlanModal = false"></div>

            <div class="fixed inset-0 overflow-hidden">
                <div class="absolute inset-0 overflow-hidden">
                    <div class="pointer-events-none fixed inset-y-0 right-0 flex max-w-full pl-10">
                        <div x-show="showPlanModal" 
                             x-transition:enter="transform transition ease-in-out duration-300 sm:duration-400" 
                             x-transition:enter-start="translate-x-full" 
                             x-transition:enter-end="translate-x-0" 
                             x-transition:leave="transform transition ease-in-out duration-300 sm:duration-400" 
                             x-transition:leave-start="translate-x-0" 
                             x-transition:leave-end="translate-x-full" 
                             class="pointer-events-auto w-screen max-w-md">
                            <div class="flex h-full flex-col overflow-y-scroll bg-white shadow-2xl">
                                
                                <div class="px-6 py-6 bg-gray-900 text-white flex justify-between items-center">
                                    <div>
                                        <h2 class="text-base font-bold text-white" x-text="isEdit ? 'Edit Subscription Plan' : 'Create Subscription Plan'"></h2>
                                        <p class="text-xs text-gray-300 mt-0.5" x-text="isEdit ? 'Modify plan pricing and resource limits' : 'Define a new SaaS tier, limits, and monthly fee.'"></p>
                                    </div>
                                    <button @click="showPlanModal = false" type="button" class="text-gray-400 hover:text-white p-1 rounded-lg transition-colors">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                    </button>
                                </div>

                                <form :action="isEdit ? '{{ url('platform/plans') }}/' + editPlanId : '{{ route('platform.plans.store') }}'" method="POST" class="p-6 space-y-4 flex-1 text-xs">
                                    @csrf
                                    <template x-if="isEdit">
                                        <input type="hidden" name="_method" value="PUT">
                                    </template>

                                    <div>
                                        <label for="plan_name" class="block font-medium text-gray-700 mb-1">Plan Name <span class="text-red-500">*</span></label>
                                        <input type="text" id="plan_name" name="name" x-model="planForm.name" required placeholder="e.g. Starter, Growth, Enterprise"
                                               class="block w-full rounded-lg border-gray-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        @error('name')
                                            <p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <div>
                                        <label for="plan_price" class="block font-medium text-gray-700 mb-1">Monthly Fee (UGX) <span class="text-red-500">*</span></label>
                                        <input type="number" id="plan_price" name="price" x-model="planForm.price" required min="0" step="1000"
                                               class="block w-full rounded-lg border-gray-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        @error('price')
                                            <p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label for="plan_trial" class="block font-medium text-gray-700 mb-1">Trial Days</label>
                                            <input type="number" id="plan_trial" name="trial_days" x-model="planForm.trial_days" required min="0"
                                                   class="block w-full rounded-lg border-gray-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                            @error('trial_days')
                                                <p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div>
                                            <label for="plan_routers" class="block font-medium text-gray-700 mb-1">Max Routers</label>
                                            <input type="number" id="plan_routers" name="max_routers" x-model="planForm.max_routers" required min="1"
                                                   class="block w-full rounded-lg border-gray-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                            @error('max_routers')
                                                <p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>
                                            @enderror
                                        </div>
                                    </div>

                                    <div>
                                        <label for="plan_customers" class="block font-medium text-gray-700 mb-1">Max End-Customers Capacity</label>
                                        <input type="number" id="plan_customers" name="max_customers" x-model="planForm.max_customers" required min="1"
                                               class="block w-full rounded-lg border-gray-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        @error('max_customers')
                                            <p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <div>
                                        <label for="plan_features" class="block font-medium text-gray-700 mb-1">Included Features (one per line)</label>
                                        <textarea id="plan_features" name="features" x-model="planForm.features" rows="4" placeholder="Priority Support&#10;Custom Captive Portal&#10;WhatsApp Notifications&#10;API Access"
                                                  class="block w-full rounded-lg border-gray-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                                        <p class="text-[11px] text-gray-400 mt-1">Separate bullet features with a new line or comma.</p>
                                    </div>

                                    <div class="pt-4 border-t border-gray-200 flex gap-2.5">
                                        <button type="button" @click="showPlanModal = false" class="flex-1 bg-white border border-gray-300 text-gray-700 py-2.5 rounded-lg text-xs font-semibold hover:bg-gray-50 transition-colors">
                                            Cancel
                                        </button>
                                        <button type="submit" class="flex-1 bg-blue-600 text-white py-2.5 rounded-lg text-xs font-semibold hover:bg-blue-700 transition-colors shadow-sm">
                                            <span x-text="isEdit ? 'Save Changes' : 'Save Platform Plan'"></span>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- ─── END SLIDE-OVER DRAWER ─── -->

    </div>
</x-app-layout>
