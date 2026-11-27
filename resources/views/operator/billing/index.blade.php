<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Billing &amp; subscription</h2>
    </x-slot>

    @php
        $currentPlan = $tenant->plan;
        $statusMap = [
            'active'   => ['label' => 'Active',     'dot' => 'bg-green-500',  'badge' => 'bg-green-50 text-green-700'],
            'trial'    => ['label' => 'Trial',      'dot' => 'bg-blue-500',   'badge' => 'bg-blue-50 text-blue-700'],
            'past_due' => ['label' => 'Past due',   'dot' => 'bg-red-500',    'badge' => 'bg-red-50 text-red-700'],
            'suspended'=> ['label' => 'Suspended',       'dot' => 'bg-gray-400',   'badge' => 'bg-gray-100 text-gray-600'],
        ];
        $status = $statusMap[$tenant->subscription_status] ?? ['label' => $tenant->subscription_status ?: 'Not recorded', 'dot' => 'bg-gray-400', 'badge' => 'bg-gray-100 text-gray-600'];
    @endphp

    <div class="py-8"
         x-data="{
             upgradeOpen: false,
             selectedPlan: @js($selectedPlanId),
             planQuotes: @js($planCheckouts)
         }"
         @keydown.escape.window="upgradeOpen = false">

        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">

            {{-- Page Header --}}
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Billing &amp; subscription</h1>
                    <p class="mt-1 text-sm text-gray-500">
                        @if($tenant->platform_plan_id)
                            Review the plan and billing details for {{ $tenant->name }}.
                        @else
                            Choose a plan and complete checkout for {{ $tenant->name }}.
                        @endif
                    </p>
                </div>
                <button type="button" @click="upgradeOpen = true"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Explore plans
                </button>
            </div>

            @if(session('status'))<p role="status" class="rounded-lg bg-green-50 p-4 text-sm text-green-900">{{ session('status') }}</p>@endif
            @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-sm text-red-900">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
            {{-- Billing not connected notice --}}
            @if(! $checkoutAvailable)
            <div role="status" class="flex gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                <div>
                    <p class="font-semibold">Subscription checkout is not connected yet.</p>
                    <p class="mt-1 text-amber-800">Subscription status is not proof of payment. You can review your current plan details, but self-serve plan changes are not enabled. Contact your platform administrator to update your plan.</p>
                </div>
            </div>
            @else
            <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                Monthly renewals are manual, not recurring. Payment remains unconfirmed until verified. Suspended accounts require administrator review.
            </div>
            @if($hasUnresolvedInvoice)
                <p class="text-sm text-amber-800">An invoice needs reconciliation. Do not submit another payment; contact support if its status remains uncertain.</p>
            @elseif($quote && $tenant->status !== 'suspended' && $tenant->subscription_status !== 'suspended')
                <form method="POST" action="{{ route('operator.billing.renew') }}" class="rounded-xl border border-gray-200 bg-white p-5">
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ $requestKey }}">
                    <input type="hidden" name="quote" value="{{ $quote['token'] }}">
                    <h2 class="font-bold">Renew current plan for one month</h2>
                    <p class="my-2 text-sm">Confirm one month of {{ $quote['plan_name'] }} for {{ $quote['currency'] }} {{ number_format($quote['amount']) }}. This quote expires in 15 minutes. This is not an automatic renewal.</p>
                    <label for="subscription-phone" class="text-sm font-semibold">Mobile money phone (+256 followed by 9 digits)</label>
                    <input id="subscription-phone" name="phone" type="tel" required pattern="\+256[0-9]{9}" value="{{ old('phone') }}" class="block rounded-lg border-gray-300 mt-2" autocomplete="tel">
                    <label class="mt-3 block text-sm"><input type="checkbox" name="confirm_quote" value="1" required> I confirm one month of {{ $quote['plan_name'] }} for {{ $quote['currency'] }} {{ number_format($quote['amount']) }}.</label>
                    <button class="mt-3 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white" type="submit">Request renewal</button>
                </form>
            @endif
            @endif

            {{-- KPI Cards --}}
            <section class="grid grid-cols-1 gap-4 sm:grid-cols-3" aria-label="Billing overview">

                {{-- Subscription Status --}}
                <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-medium text-gray-500">Subscription status</p>
                    <div class="mt-3 flex items-center gap-2">
                        <span class="h-2.5 w-2.5 rounded-full {{ $status['dot'] }}"></span>
                        <span class="text-lg font-bold text-gray-900">{{ $status['label'] }}</span>
                    </div>
                    @if($tenant->trial_ends_at)
                        <p class="mt-2 text-xs text-gray-500">Trial ends {{ $tenant->trial_ends_at->format('d M Y') }}</p>
                    @endif
                    @if($tenant->subscription_ends_at)
                        <p class="mt-2 text-xs text-gray-500">Subscription ends {{ $tenant->subscription_ends_at->format('d M Y') }}</p>
                    @endif
                    @if(! $tenant->trial_ends_at && ! $tenant->subscription_ends_at)
                        <p class="mt-2 text-xs text-gray-500">No subscription dates recorded</p>
                    @endif
                </article>

                {{-- Current Plan --}}
                <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-medium text-gray-500">Current plan</p>
                    @if($currentPlan)
                        <p class="mt-3 text-lg font-bold text-gray-900">{{ $currentPlan->name }}</p>
                        <p class="mt-2 text-xs text-gray-500">Listed plan price: {{ $currency }} {{ number_format((float) $currentPlan->price) }} / month</p>
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
                        <p class="mt-1 text-sm text-gray-500">Your workspace plan, limits, and recorded status.</p>
                    </div>
                    @if($currentPlan)
                        <span class="inline-flex w-fit items-center rounded-full px-3 py-1 text-xs font-semibold {{ $status['badge'] }}">
                            {{ $status['label'] }}
                        </span>
                    @else
                        <span class="inline-flex w-fit items-center rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600">No plan assigned</span>
                    @endif
                </div>

                @if($currentPlan)
                <div class="grid gap-5 p-6 md:grid-cols-3">
                    <div class="md:col-span-2 space-y-4">
                        <div>
                            <p class="text-sm font-bold text-gray-900">{{ $currentPlan->name }} Plan</p>
                            <p class="mt-1 text-sm text-gray-500">Listed plan price: {{ $currency }} {{ number_format((float) $currentPlan->price) }}/month</p>
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
                    <p class="mt-1 text-sm text-gray-500">Only invoices created here are shown. Earlier or manually administered payments are not recorded here.</p>
                </div>
                @if($invoices->isEmpty())
                    <div class="px-6 py-8 text-center"><p class="text-sm font-semibold text-gray-900">Billing history unavailable</p><p class="mt-1 text-sm text-gray-500">No portal-issued invoices are recorded for this page.</p></div>
                @else
                    <div class="overflow-x-auto p-6"><table class="w-full text-left text-sm"><thead><tr><th>Invoice</th><th>Plan</th><th>Amount</th><th>Invoice status</th><th>Payment status</th><th>Reference</th><th></th></tr></thead><tbody>
                    @foreach($invoices as $invoice)
                        <tr class="border-t"><td class="py-3"><a class="font-semibold text-blue-700 underline" href="{{ route('operator.billing.invoices.show', ['invoice' => $invoice->id]) }}">#{{ $invoice->id }}</a><br>{{ $invoice->created_at }}</td><td>{{ $invoice->plan_name }}</td><td>{{ $invoice->currency }} {{ number_format($invoice->amount) }}</td><td>{{ $invoice->status === 'review' ? 'Paid - administrator review required' : ucfirst($invoice->status) }}</td><td>{{ ucfirst($invoice->attempt_status ?? 'unknown') }}</td><td>{{ $invoice->reference }}</td><td>
                        @if($invoice->verification_available && $invoice->status === 'open' && in_array($invoice->attempt_status, ['unknown', 'pending', 'failed'], true))
                            <form method="POST" action="{{ route('operator.billing.check', ['attempt' => $invoice->attempt_id]) }}">@csrf<button type="submit" class="text-blue-700 underline">Check status</button></form>
                        @endif
                        </td></tr>
                    @endforeach
                    </tbody></table>{{ $invoices->withQueryString()->links() }}</div>
                @endif
                @if($history->isNotEmpty())
                    <div class="border-t p-6"><h3 class="font-semibold">Recorded events</h3>
                    @foreach($history as $entry)
                        @php $snapshot = json_decode($entry->snapshot, true) ?: []; @endphp
                        <p class="mt-2 text-sm">{{ $entry->created_at }} — Invoice #{{ $entry->subscription_invoice_id }} — {{ str_replace('_', ' ', $entry->event) }} — {{ $snapshot['plan_name'] ?? '' }} — {{ $snapshot['currency'] ?? '' }} {{ number_format($snapshot['amount'] ?? 0) }}</p>
                    @endforeach
                    {{ $history->withQueryString()->links() }}</div>
                @endif
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
                            <h2 id="upgrade-title" class="text-lg font-bold text-gray-900">
                                {{ $currentPlan ? 'Available Plans' : 'Choose your plan' }}
                            </h2>
                            <p class="mt-1 text-sm text-gray-500">
                                @if($currentPlan)
                                    Review available tiers. To switch plans, contact your platform administrator.
                                @else
                                    Select a plan below. Your subscription starts after the payment is verified.
                                @endif
                            </p>
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
                                @php
                                    $isCurrentPlan = $currentPlan && $currentPlan->id === $plan->id;
                                    $isSelectable = ! $currentPlan && isset($planCheckouts[$plan->id]);
                                @endphp
                                @if($isSelectable)
                                    <button type="button" @click="selectedPlan = {{ $plan->id }}"
                                            :class="selectedPlan === {{ $plan->id }} ? 'border-blue-500 bg-blue-50 ring-1 ring-blue-500' : 'border-gray-200 bg-white'"
                                            :aria-pressed="selectedPlan === {{ $plan->id }}"
                                            class="relative block w-full rounded-xl border p-4 text-left transition">
                                @else
                                    <div class="relative rounded-xl border p-4 text-left transition
                                                {{ $isCurrentPlan ? 'border-blue-500 bg-blue-50 ring-1 ring-blue-500' : 'border-gray-200 bg-white' }}">
                                @endif

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
                                        @if($plan->trial_days && $currentPlan)
                                        <div>
                                            <dt class="font-medium text-gray-400 uppercase tracking-wide">Trial period</dt>
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
                                @if($isSelectable)
                                    </button>
                                @else
                                    </div>
                                @endif
                                @if(! $currentPlan && ! isset($planCheckouts[$plan->id]))
                                    <p class="mt-1 text-xs text-amber-700">Checkout is unavailable for this plan. Contact the platform administrator.</p>
                                @endif
                            @endforeach
                        @endif

                        @if(! $currentPlan && $checkoutAvailable && count($planCheckouts) > 0 && ! $hasUnresolvedInvoice)
                            <form id="initial-plan-checkout" method="POST" action="{{ route('operator.billing.renew') }}" class="rounded-xl border border-blue-200 bg-blue-50 p-4">
                                @csrf
                                <input type="hidden" name="idempotency_key" :value="planQuotes[selectedPlan] ? planQuotes[selectedPlan].key : ''">
                                <input type="hidden" name="quote" :value="planQuotes[selectedPlan] ? planQuotes[selectedPlan].quote : ''">
                                <label for="initial-subscription-phone" class="text-sm font-semibold text-gray-900">Mobile money phone (+256 followed by 9 digits)</label>
                                <input id="initial-subscription-phone" name="phone" type="tel" required pattern="\+256[0-9]{9}" value="{{ old('phone') }}" class="mt-2 block w-full rounded-lg border-gray-300" autocomplete="tel">
                                <label class="mt-3 flex items-start gap-2 text-sm text-gray-700">
                                    <input type="checkbox" name="confirm_quote" value="1" required class="mt-1">
                                    <span>I confirm one month of <strong x-text="planQuotes[selectedPlan] ? planQuotes[selectedPlan].name : ''"></strong> for <strong x-text="planQuotes[selectedPlan] ? planQuotes[selectedPlan].amount : ''"></strong>.</span>
                                </label>
                            </form>
                        @endif

                        <div class="rounded-xl border border-gray-200 p-4">
                            <h3 class="text-sm font-semibold text-gray-900">Your Workspace</h3>
                            <p class="mt-2 text-sm text-gray-700">{{ $tenant->name }}</p>
                            <p class="mt-1 text-xs text-gray-500">{{ $tenant->host() }}</p>
                        </div>
                    </div>

                    <footer class="border-t border-gray-200 bg-white p-6">
                        @if($currentPlan)
                            <button type="button" disabled
                                    class="w-full cursor-not-allowed rounded-lg bg-gray-300 px-4 py-3 text-sm font-semibold text-gray-600"
                                    title="Contact your platform admin to switch plans">
                                Self-serve plan changes coming soon
                            </button>
                            <p class="mt-3 text-center text-xs text-gray-500">To change your plan, contact the platform administrator.</p>
                        @elseif($checkoutAvailable && count($planCheckouts) > 0 && ! $hasUnresolvedInvoice)
                            <button type="submit" form="initial-plan-checkout"
                                    class="w-full rounded-lg bg-blue-600 px-4 py-3 text-sm font-semibold text-white hover:bg-blue-700">
                                Continue to secure checkout
                            </button>
                            <p class="mt-3 text-center text-xs text-gray-500">You will be redirected to the active payment gateway. Your plan activates after payment verification.</p>
                        @elseif($hasUnresolvedInvoice)
                            <p class="text-center text-sm text-amber-800">An invoice is awaiting reconciliation. Do not submit another payment.</p>
                        @elseif(! $checkoutAvailable)
                            <p class="text-center text-sm text-amber-800">Subscription checkout is currently unavailable. Contact the platform administrator.</p>
                        @else
                            <p class="text-center text-sm text-amber-800">No configured plan is currently available for checkout. Contact the platform administrator.</p>
                        @endif
                    </footer>
                </section>
            </div>
        </div>

    </div>
</x-app-layout>
