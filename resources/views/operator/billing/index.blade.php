<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Billing &amp; subscription</h2>
    </x-slot>

    <div class="py-8" x-data="{ upgradeOpen: false, selectedPlan: 'growth' }" @keydown.escape.window="upgradeOpen = false">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Billing &amp; subscription</h1>
                    <p class="mt-1 text-sm text-gray-500">Manage the plan and billing details for {{ $tenant->name }}.</p>
                </div>
                <button type="button" @click="upgradeOpen = true" class="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Explore plans
                </button>
            </div>

            <div role="status" class="flex gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                <div>
                    <p class="font-semibold">Subscription billing is not connected yet.</p>
                    <p class="mt-1 text-amber-800">You can review the available plan options, but checkout and payment processing are not enabled. No payment will be collected from this page.</p>
                </div>
            </div>

            <section class="grid grid-cols-1 gap-4 sm:grid-cols-3" aria-label="Billing overview">
                <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-medium text-gray-500">Subscription status</p>
                    <div class="mt-3 flex items-center gap-2">
                        <span class="h-2.5 w-2.5 rounded-full bg-gray-400"></span>
                        <span class="text-lg font-bold text-gray-900">Not configured</span>
                    </div>
                    <p class="mt-2 text-xs text-gray-500">A subscription plan has not been linked to this workspace.</p>
                </article>
                <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-medium text-gray-500">Wallet balance</p>
                    <p class="mt-3 text-lg font-bold text-gray-900">Unavailable</p>
                    <p class="mt-2 text-xs text-gray-500">Wallet balances appear when a payment provider is connected.</p>
                </article>
                <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-medium text-gray-500">This month</p>
                    <p class="mt-3 text-lg font-bold text-gray-900">Unavailable</p>
                    <p class="mt-2 text-xs text-gray-500">Billing totals are not currently tracked by this application.</p>
                </article>
            </section>

            <section class="rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="flex flex-col gap-3 border-b border-gray-100 p-6 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-base font-bold text-gray-900">Current subscription</h2>
                        <p class="mt-1 text-sm text-gray-500">Your workspace plan and renewal information will appear here.</p>
                    </div>
                    <span class="inline-flex w-fit items-center rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600">No active plan</span>
                </div>
                <div class="grid gap-5 p-6 md:grid-cols-3">
                    <div class="md:col-span-2">
                        <p class="text-sm font-semibold text-gray-900">Plan details not available</p>
                        <p class="mt-1 text-sm text-gray-500">Subscription dates, included limits, and payment history will be shown after billing is configured.</p>
                    </div>
                    <div class="flex items-center md:justify-end">
                        <button type="button" @click="upgradeOpen = true" class="w-full rounded-lg border border-blue-200 px-4 py-2.5 text-sm font-semibold text-blue-700 hover:bg-blue-50 md:w-auto">Compare plans</button>
                    </div>
                </div>
            </section>

            <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 p-6">
                    <h2 class="text-base font-bold text-gray-900">Billing history</h2>
                    <p class="mt-1 text-sm text-gray-500">Invoices and payments for this workspace.</p>
                </div>
                <div class="px-6 py-12 text-center">
                    <div class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-gray-100 text-gray-500">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14h6m-6-4h6m2 10H7a2 2 0 01-2-2V6a2 2 0 012-2h6l6 6v8a2 2 0 01-2 2z"/></svg>
                    </div>
                    <p class="mt-3 text-sm font-semibold text-gray-900">No billing history yet</p>
                    <p class="mt-1 text-sm text-gray-500">Invoices will appear here after a payment integration is set up.</p>
                </div>
            </section>
        </div>

        <div x-cloak x-show="upgradeOpen" class="fixed inset-0 z-50 overflow-hidden" aria-labelledby="upgrade-title" role="dialog" aria-modal="true">
            <div class="absolute inset-0 bg-gray-900/50" @click="upgradeOpen = false" x-transition.opacity></div>
            <div class="absolute inset-y-0 right-0 flex max-w-full pl-10">
                <section x-show="upgradeOpen" x-transition:enter="transform transition ease-in-out duration-300" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transform transition ease-in-out duration-300" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full" class="flex h-full w-screen max-w-xl flex-col bg-white shadow-2xl">
                    <header class="flex items-start justify-between border-b border-gray-200 px-6 py-5">
                        <div>
                            <h2 id="upgrade-title" class="text-lg font-bold text-gray-900">Choose a plan</h2>
                            <p class="mt-1 text-sm text-gray-500">Select a plan to review. Checkout is not available yet.</p>
                        </div>
                        <button type="button" @click="upgradeOpen = false" class="rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600" aria-label="Close plan selector">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </header>

                    <div class="flex-1 space-y-5 overflow-y-auto p-6">
                        <div class="rounded-lg border border-blue-100 bg-blue-50 p-4 text-sm text-blue-900">
                            Plan pricing and limits have not been configured for this application. Selecting an option below does not start a subscription.
                        </div>

                        <div class="space-y-3">
                            <template x-for="plan in [
                                { id: 'starter', name: 'Starter', description: 'For a growing WiFi workspace' },
                                { id: 'growth', name: 'Growth', description: 'For established network operators' },
                                { id: 'business', name: 'Business', description: 'For larger teams and deployments' }
                            ]" :key="plan.id">
                                <button type="button" @click="selectedPlan = plan.id" :aria-pressed="selectedPlan === plan.id" :class="selectedPlan === plan.id ? 'border-blue-500 bg-blue-50 ring-1 ring-blue-500' : 'border-gray-200 hover:border-gray-300'" class="flex w-full items-center justify-between rounded-xl border p-4 text-left transition">
                                    <span>
                                        <span class="block text-sm font-bold text-gray-900" x-text="plan.name"></span>
                                        <span class="mt-1 block text-xs text-gray-500" x-text="plan.description"></span>
                                        <span class="mt-2 block text-xs font-medium text-gray-700">Pricing and limits to be announced</span>
                                    </span>
                                    <span class="ml-4 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border" :class="selectedPlan === plan.id ? 'border-blue-600' : 'border-gray-300'">
                                        <span x-show="selectedPlan === plan.id" class="h-2.5 w-2.5 rounded-full bg-blue-600"></span>
                                    </span>
                                </button>
                            </template>
                        </div>

                        <div class="rounded-xl border border-gray-200 p-4">
                            <h3 class="text-sm font-semibold text-gray-900">Workspace</h3>
                            <p class="mt-2 text-sm text-gray-700">{{ $tenant->name }}</p>
                            <p class="mt-1 text-xs text-gray-500">{{ $tenant->slug }}.localhost</p>
                        </div>
                    </div>

                    <footer class="border-t border-gray-200 bg-white p-6">
                        <button type="button" disabled class="w-full cursor-not-allowed rounded-lg bg-gray-300 px-4 py-3 text-sm font-semibold text-gray-600" title="Checkout is not configured">Continue to checkout (unavailable)</button>
                        <p class="mt-3 text-center text-xs text-gray-500">No payment will be submitted or collected.</p>
                    </footer>
                </section>
            </div>
        </div>
    </div>
</x-app-layout>
