<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Billing &amp; subscription</h2>
    </x-slot>

    @php
        $currentPlan = $tenant->plan;
        $statusMap = [
            'active'   => ['label' => 'Active (Paid)',   'dot' => 'bg-green-500',  'badge' => 'bg-green-50 text-green-700'],
            'trial'    => ['label' => '14-Day Trial',    'dot' => 'bg-blue-500',   'badge' => 'bg-blue-50 text-blue-700'],
            'past_due' => ['label' => 'Overdue',         'dot' => 'bg-red-500',    'badge' => 'bg-red-50 text-red-700'],
            'suspended'=> ['label' => 'Suspended',       'dot' => 'bg-gray-400',   'badge' => 'bg-gray-100 text-gray-600'],
        ];
        $status = $statusMap[$tenant->subscription_status] ?? $statusMap['suspended'];
    @endphp

    <div class="py-8"
         x-data="{
             upgradeOpen: false,
             selectedPlan: {{ $currentPlan ? $currentPlan->id : ($plans->first()?->id ?? 'null') }}
         }"
         @keydown.escape.window="upgradeOpen = false">

        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">

            {{-- Page Header --}}
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Billing &amp; subscription</h1>
                    <p class="mt-1 text-sm text-gray-500">Manage the plan and billing details for {{ $tenant->name }}.</p>
                </div>
                <button type="button" @click="upgradeOpen = true"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Explore plans
                </button>
            </div>

            {{-- Billing not connected notice --}}
            <div role="status" class="flex gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                <div>
                    <p class="font-semibold">Subscription checkout is not connected yet.</p>
                    <p class="mt-1 text-amber-800">You can review your current plan details, but self-serve plan changes are not enabled. Contact your platform administrator to update your plan.</p>
                </div>
            </div>

            {{-- KPI Cards --}}
            <section class="grid grid-cols-1 gap-4 sm:grid-cols-3" aria-label="Billing overview">

                {{-- Subscription Status --}}
                <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-medium text-gray-500">Subscription status</p>
                    <div class="mt-3 flex items-center gap-2">
                        <span class="h-2.5 w-2.5 rounded-full {{ $status['dot'] }}"></span>
                        <span class="text-lg font-bold text-gray-900">{{ $status['label'] }}</span>
                    </div>
                    @if($tenant->trial_ends_at && $tenant->subscription_status === 'trial')
                        <p class="mt-2 text-xs text-blue-600 font-medium">Trial ends {{ $tenant->trial_ends_at->diffForHumans() }}</p>
                    @elseif($tenant->subscription_ends_at)
                        <p class="mt-2 text-xs text-gray-500">Renews {{ $tenant->subscription_ends_at->format('d M Y') }}</p>
                    @else
                        <p class="mt-2 text-xs text-gray-500">No renewal date set</p>
                    @endif
                </article>

                {{-- Current Plan --}}
                <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-medium text-gray-500">Current plan</p>
                    @if($currentPlan)
                        <p class="mt-3 text-lg font-bold text-gray-900">{{ $currentPlan->name }}</p>
                        <p class="mt-2 text-xs text-gray-500">{{ $currency }} {{ number_format((float) $currentPlan->price) }} / month</p>
                    @else
                        <p class="mt-3 text-lg font-bold text-gray-400">No plan assigned</p>
                        <p class="mt-2 text-xs text-gray-500">Contact the platform admin to assign a plan.</p>
                    @endif
                </article>

                {{-- Wallet Balance --}}
                <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-medium text-gray-500">Wallet balance</p>
                    <p class="mt-3 text-lg font-bold text-gray-900">{{ $currency }} {{ number_format((float) ($tenant->wallet_balance ?? 0)) }}</p>
                    <a href="{{ route('operator.wallet.index') }}" class="mt-2 inline-block text-xs font-semibold text-blue-600 hover:text-blue-800">Manage wallet &amp; payouts &rarr;</a>
                </article>

            </section>

            {{-- Current Subscription Detail Card --}}
            <section class="rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="flex flex-col gap-3 border-b border-gray-100 p-6 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-base font-bold text-gray-900">Current subscription</h2>
                        <p class="mt-1 text-sm text-gray-500">Your workspace plan, limits, and renewal information.</p>
                    </div>
                    @if($currentPlan)
                        <span class="inline-flex w-fit items-center rounded-full px-3 py-1 text-xs font-semibold {{ $status['badge'] }}">
                            {{ $status['label'] }}
                        </span>
                    @else
                        <span class="inline-flex w-fit items-center rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600">No active plan</span>
                    @endif
                </div>

                @if($currentPlan)
                <div class="grid gap-5 p-6 md:grid-cols-3">
                    <div class="md:col-span-2 space-y-4">
                        <div>
                            <p class="text-sm font-bold text-gray-900">{{ $currentPlan->name }} Plan</p>
                            <p class="mt-1 text-sm text-gray-500">{{ $currency }} {{ number_format((float) $currentPlan->price) }}/month</p>
                        </div>
                        <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-xs">
                            <div>
                                <dt class="font-medium text-gray-500 uppercase tracking-wide">Max Customers</dt>
                                <dd class="mt-1 font-bold text-gray-900">{{ number_format($currentPlan->max_customers) }}</dd>
                            </div>
                            <div>
                                <dt class="font-medium text-gray-500 uppercase tracking-wide">Max Routers</dt>
                                <dd class="mt-1 font-bold text-gray-900">{{ $currentPlan->max_routers }}</dd>
                            </div>
                            @if($currentPlan->trial_days)
                            <div>
                                <dt class="font-medium text-gray-500 uppercase tracking-wide">Free Trial</dt>
                                <dd class="mt-1 font-bold text-gray-900">{{ $currentPlan->trial_days }} days</dd>
                            </div>
                            @endif
                            @if(!empty($currentPlan->features) && is_array($currentPlan->features))
                            <div class="col-span-2">
                                <dt class="font-medium text-gray-500 uppercase tracking-wide mb-1">Included Features</dt>
                                <dd class="flex flex-wrap gap-1">
                                    @foreach($currentPlan->features as $feature)
                                        <span class="inline-flex items-center gap-1 text-xs bg-green-50 text-green-700 border border-green-200 px-2 py-0.5 rounded-full font-medium">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                            {{ $feature }}
                                        </span>
                                    @endforeach
                                </dd>
                            </div>
                            @endif
                        </dl>
                    </div>
                    <div class="flex items-center md:justify-end">
                        <button type="button" @click="upgradeOpen = true"
                                class="w-full rounded-lg border border-blue-200 px-4 py-2.5 text-sm font-semibold text-blue-700 hover:bg-blue-50 md:w-auto">
                            Compare plans
                        </button>
                    </div>
                </div>
                @else
                <div class="grid gap-5 p-6 md:grid-cols-3">
                    <div class="md:col-span-2">
                        <p class="text-sm font-semibold text-gray-900">Plan details not available</p>
                        <p class="mt-1 text-sm text-gray-500">No subscription plan has been linked to this workspace. Contact the platform administrator to assign a plan.</p>
                    </div>
                    <div class="flex items-center md:justify-end">
                        <button type="button" @click="upgradeOpen = true"
                                class="w-full rounded-lg border border-blue-200 px-4 py-2.5 text-sm font-semibold text-blue-700 hover:bg-blue-50 md:w-auto">
                            Compare plans
                        </button>
                    </div>
                </div>
                @endif
            </section>

            {{-- Billing History --}}
            <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 p-6">
                    <h2 class="text-base font-bold text-gray-900">Billing history</h2>
                    <p class="mt-1 text-sm text-gray-500">Invoices and payments for this workspace.</p>
                </div>
                <div class="px-6 py-12 text-center">
                    <div class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-gray-100 text-gray-500">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14h6m-6-4h6m2 10H7a2 2 0 01-2-2V6a2 2 0 012-2h6l6 6v8a2 2 0 01-2 2z"/>
                        </svg>
                    </div>
                    <p class="mt-3 text-sm font-semibold text-gray-900">No billing history yet</p>
                    <p class="mt-1 text-sm text-gray-500">Invoices will appear here after a payment integration is set up.</p>
                </div>
            </section>

        </div>

        {{-- ─── EXPLORE PLANS SIDE OVERLAY ─── --}}
        <div x-cloak x-show="upgradeOpen" class="fixed inset-0 z-50 overflow-hidden"
             aria-labelledby="upgrade-title" role="dialog" aria-modal="true">
            <div class="absolute inset-0 bg-gray-900/50" @click="upgradeOpen = false" x-transition.opacity></div>
            <div class="absolute inset-y-0 right-0 flex max-w-full pl-10">
                <section x-show="upgradeOpen"
                         x-transition:enter="transform transition ease-in-out duration-300"
                         x-transition:enter-start="translate-x-full"
                         x-transition:enter-end="translate-x-0"
                         x-transition:leave="transform transition ease-in-out duration-300"
                         x-transition:leave-start="translate-x-0"
                         x-transition:leave-end="translate-x-full"
                         class="flex h-full w-screen max-w-xl flex-col bg-white shadow-2xl">

                    <header class="flex items-start justify-between border-b border-gray-200 px-6 py-5">
                        <div>
                            <h2 id="upgrade-title" class="text-lg font-bold text-gray-900">Available Plans</h2>
                            <p class="mt-1 text-sm text-gray-500">Review available tiers. To switch plans, contact your platform administrator.</p>
                        </div>
                        <button type="button" @click="upgradeOpen = false"
                                class="rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600" aria-label="Close plan selector">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </header>

                    <div class="flex-1 space-y-4 overflow-y-auto p-6">

                        @if($plans->isEmpty())
                            <div class="rounded-lg border border-blue-100 bg-blue-50 p-4 text-sm text-blue-900">
                                No subscription plans have been configured yet. Contact the platform administrator.
                            </div>
                        @else
                            @foreach($plans as $plan)
                                @php $isCurrentPlan = $currentPlan && $currentPlan->id === $plan->id; @endphp
                                <div class="relative rounded-xl border p-4 text-left transition
                                            {{ $isCurrentPlan ? 'border-blue-500 bg-blue-50 ring-1 ring-blue-500' : 'border-gray-200 bg-white' }}">

                                    {{-- "Current" badge --}}
                                    @if($isCurrentPlan)
                                        <span class="absolute top-3 right-3 text-xs font-bold text-blue-700 bg-blue-100 px-2 py-0.5 rounded-full">Current Plan</span>
                                    @endif

                                    <div class="flex items-start justify-between gap-4 pr-20">
                                        <div>
                                            <p class="text-sm font-bold text-gray-900">{{ $plan->name }}</p>
                                            <p class="mt-0.5 text-xs text-gray-500">{{ $currency }} {{ number_format((float) $plan->price) }} / month</p>
                                        </div>
                                    </div>

                                    <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-xs text-gray-600">
                                        <div>
                                            <dt class="font-medium text-gray-400 uppercase tracking-wide">Customers</dt>
                                            <dd class="font-bold text-gray-900">{{ number_format($plan->max_customers) }}</dd>
                                        </div>
                                        <div>
                                            <dt class="font-medium text-gray-400 uppercase tracking-wide">Routers</dt>
                                            <dd class="font-bold text-gray-900">{{ $plan->max_routers }}</dd>
                                        </div>
                                        @if($plan->trial_days)
                                        <div>
                                            <dt class="font-medium text-gray-400 uppercase tracking-wide">Free Trial</dt>
                                            <dd class="font-bold text-gray-900">{{ $plan->trial_days }} days</dd>
                                        </div>
                                        @endif
                                    </dl>

                                    @if(!empty($plan->features) && is_array($plan->features))
                                        <div class="mt-3 flex flex-wrap gap-1">
                                            @foreach($plan->features as $feature)
                                                <span class="inline-flex items-center gap-1 text-[11px] bg-green-50 text-green-700 border border-green-200 px-2 py-0.5 rounded-full font-medium">
                                                    <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                                                    </svg>
                                                    {{ $feature }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        @endif

                        <div class="rounded-xl border border-gray-200 p-4">
                            <h3 class="text-sm font-semibold text-gray-900">Your Workspace</h3>
                            <p class="mt-2 text-sm text-gray-700">{{ $tenant->name }}</p>
                            <p class="mt-1 text-xs text-gray-500">{{ $tenant->host() }}</p>
                        </div>
                    </div>

                    <footer class="border-t border-gray-200 bg-white p-6">
                        <button type="button" disabled
                                class="w-full cursor-not-allowed rounded-lg bg-gray-300 px-4 py-3 text-sm font-semibold text-gray-600"
                                title="Contact your platform admin to switch plans">
                            Self-serve plan changes coming soon
                        </button>
                        <p class="mt-3 text-center text-xs text-gray-500">To change your plan, contact the platform administrator.</p>
                    </footer>
                </section>
            </div>
        </div>

    </div>
</x-app-layout>
