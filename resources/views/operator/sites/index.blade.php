<x-app-layout>
    <div class="min-h-full bg-gray-50 px-4 py-6 sm:px-6 lg:px-8" x-data="{ addSiteOpen: {{ $errors->any() ? 'true' : 'false' }} }" @keydown.escape.window="addSiteOpen = false">
        <div class="mx-auto max-w-7xl space-y-6">
            @if (session('status'))
                <div role="status" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div role="alert" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>
            @endif

            <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="mb-2 flex items-center gap-2 text-xs text-gray-400">
                        <a href="{{ route('operator.dashboard') }}" class="hover:text-blue-600">{{ $tenant->name }}</a>
                        <span aria-hidden="true">›</span>
                        <span class="font-medium text-gray-600">Sites &amp; hotspots</span>
                    </div>
                    <h1 class="text-2xl font-bold tracking-tight text-gray-900">Sites &amp; hotspots</h1>
                    <p class="mt-1 text-sm text-gray-500">Manage your network locations and track activity from connected routers.</p>
                </div>
                <div class="flex shrink-0 gap-2">
                    <a href="{{ route('operator.sites.export', ['tenant' => $tenant->slug, 'currency' => $currency]) }}" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        Export sites
                    </a>
                    @if (auth()->user()->hasAnyRole(['operator_admin', 'operator_network']))
                        <button type="button" @click="addSiteOpen = true" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            Add site
                        </button>
                    @endif
                </div>
            </header>

            <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Site overview">
                <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium text-gray-500">Operating sites</p>
                    <p class="mt-2 text-2xl font-bold text-gray-900">{{ number_format($operatingSites) }}</p>
                    <p class="mt-1 text-xs text-gray-500">Sites with at least one online router</p>
                </article>
                <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium text-gray-500">Customers covered</p>
                    <p class="mt-2 text-2xl font-bold text-gray-900">{{ number_format($customersCovered) }}</p>
                    <p class="mt-1 text-xs text-gray-500">Customers linked to site sessions</p>
                </article>
                <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium text-gray-500">Live connections</p>
                    <p class="mt-2 text-2xl font-bold text-gray-900">{{ number_format($liveConnections) }}</p>
                    <p class="mt-1 text-xs text-gray-500">Active, unexpired sessions</p>
                </article>
                <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium text-gray-500">Sites with alerts</p>
                    <p class="mt-2 text-2xl font-bold text-gray-900">{{ number_format($sitesWithAlerts) }}</p>
                    <p class="mt-1 text-xs text-orange-600">Sites with an offline router</p>
                </article>
            </section>

            <section class="space-y-4">
                <form method="GET" action="{{ route('operator.sites.index', ['tenant' => $tenant->slug]) }}" class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <label class="relative block flex-1">
                        <span class="sr-only">Search sites</span>
                        <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-4.3-4.3m1.3-5.2a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <input type="search" name="search" value="{{ request('search') }}" placeholder="Search site name or location" class="w-full rounded-lg border-gray-200 bg-white py-2.5 pl-9 pr-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </label>
                    <label>
                        <span class="sr-only">Revenue currency</span>
                        <select name="currency" onchange="this.form.submit()" class="w-full rounded-lg border-gray-200 bg-white py-2.5 pl-3 pr-9 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:w-auto">
                            @foreach ($currencies as $availableCurrency)
                                <option value="{{ $availableCurrency }}" @selected($currency === $availableCurrency)>{{ $availableCurrency }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>
                        <span class="sr-only">Filter site health</span>
                        <select name="status" onchange="this.form.submit()" class="w-full rounded-lg border-gray-200 bg-white py-2.5 pl-3 pr-9 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:w-auto">
                            <option value="all" @selected($statusFilter === 'all')>All health statuses</option>
                            <option value="healthy" @selected($statusFilter === 'healthy')>Healthy</option>
                            <option value="degraded" @selected($statusFilter === 'degraded')>Degraded</option>
                            <option value="offline" @selected($statusFilter === 'offline')>Offline</option>
                            <option value="unassigned" @selected($statusFilter === 'unassigned')>No routers</option>
                        </select>
                    </label>
                    <button type="submit" class="rounded-lg border border-gray-200 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">Search</button>
                </form>

                @if ($sites->isEmpty())
                    <div class="rounded-xl border border-dashed border-gray-300 bg-white px-6 py-14 text-center">
                        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-blue-50 text-blue-600">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657 13.414 20.9a2 2 0 01-2.828 0l-4.243-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        </div>
                        <h2 class="mt-4 text-sm font-semibold text-gray-900">{{ request('search') || $statusFilter !== 'all' ? 'No matching sites' : 'No sites configured' }}</h2>
                        <p class="mx-auto mt-1 max-w-md text-sm text-gray-500">Add a site, then associate routers with it to track connections and voucher collections by location.</p>
                        @if (auth()->user()->hasAnyRole(['operator_admin', 'operator_network']))
                            <button type="button" @click="addSiteOpen = true" class="mt-5 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Add your first site</button>
                        @endif
                    </div>
                @else
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($sites as $site)
                            @php
                                $healthStyles = [
                                    'healthy' => 'bg-emerald-50 text-emerald-700',
                                    'degraded' => 'bg-amber-50 text-amber-700',
                                    'offline' => 'bg-red-50 text-red-700',
                                    'unassigned' => 'bg-gray-100 text-gray-600',
                                ];
                                $healthLabels = [
                                    'healthy' => 'Healthy',
                                    'degraded' => 'Degraded',
                                    'offline' => 'Offline',
                                    'unassigned' => 'No routers',
                                ];
                            @endphp
                            <article class="flex min-h-64 flex-col rounded-xl border border-gray-200 bg-white p-5 shadow-sm transition hover:shadow-md">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600">
                                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21h18M5 21V7l8-4v18m6 0V11l-6-4M9 9v.01M9 12v.01M9 15v.01M9 18v.01m8-4v.01m0 3v.01"/></svg>
                                        </span>
                                        <div class="min-w-0">
                                            <h2 class="truncate text-sm font-bold text-gray-900">{{ $site->name }}</h2>
                                            <p class="mt-0.5 truncate text-xs text-gray-500">{{ $site->location ?: 'Location not specified' }}</p>
                                        </div>
                                    </div>
                                    <span class="shrink-0 rounded-full px-2.5 py-1 text-[10px] font-semibold {{ $healthStyles[$site->health_status] ?? $healthStyles['unassigned'] }}">{{ $healthLabels[$site->health_status] ?? 'Unknown' }}</span>
                                </div>

                                <div class="mt-4 flex items-center justify-between border-t border-gray-100 pt-3 text-xs text-gray-500">
                                    <span>{{ number_format($site->routers_online_count) }} / {{ number_format($site->routers->count()) }} routers online</span>
                                    @if ($site->health_status === 'degraded' || $site->health_status === 'offline')
                                        <span class="font-medium text-orange-700">{{ $site->routers->where('status', 'offline')->count() }} offline</span>
                                    @endif
                                </div>

                                <div class="mt-3 grid grid-cols-2 gap-3 border-b border-gray-100 pb-4">
                                    <div>
                                        <p class="text-[11px] text-gray-500">Customers covered</p>
                                        <p class="mt-1 text-lg font-bold text-gray-900">{{ number_format($site->customers_covered_count) }}</p>
                                    </div>
                                    <div>
                                        <p class="text-[11px] text-gray-500">Live connections</p>
                                        <p class="mt-1 text-lg font-bold text-gray-900">{{ number_format($site->live_connections_count) }}</p>
                                    </div>
                                </div>

                                <div class="mt-auto flex items-end justify-between gap-3 pt-4">
                                    <div>
                                        <p class="text-[10px] font-medium uppercase tracking-wide text-gray-400">This month collections</p>
                                        <p class="mt-1 text-sm font-bold text-gray-900">{{ $currency }} {{ number_format($site->monthly_revenue) }}</p>
                                    </div>
                                    <a href="{{ route('operator.routers.index', ['tenant' => $tenant->slug, 'site_id' => $site->id]) }}" class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-blue-600 hover:text-blue-800">
                                        View routers
                                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14m-7-7 7 7-7 7"/></svg>
                                    </a>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="rounded-xl border border-gray-200 bg-white px-5 py-4 shadow-sm">
                <h2 class="text-sm font-semibold text-gray-900">Site activity tracking</h2>
                <p class="mt-1 text-xs leading-5 text-gray-500">Sessions and voucher revenue are attributed to a site when a connecting router is assigned to it. Connections without a known site remain unassigned and are not included in site totals.</p>
            </section>
        </div>

        <div x-cloak x-show="addSiteOpen" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-4" role="dialog" aria-modal="true" aria-labelledby="add-site-title">
            <button type="button" class="absolute inset-0 cursor-default bg-gray-900/50" @click="addSiteOpen = false" aria-label="Close dialog"></button>
            <section class="relative w-full max-w-lg rounded-xl bg-white p-6 shadow-2xl">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 id="add-site-title" class="text-lg font-bold text-gray-900">Add a site</h2>
                        <p class="mt-1 text-sm text-gray-500">Create a location for this workspace and assign routers to it.</p>
                    </div>
                    <button type="button" @click="addSiteOpen = false" class="rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700" aria-label="Close dialog">&times;</button>
                </div>
                <form method="POST" action="{{ route('operator.sites.store', ['tenant' => $tenant->slug]) }}" class="mt-5 space-y-4">
                    @csrf
                    <div>
                        <label for="site-name" class="block text-sm font-medium text-gray-700">Site name</label>
                        <input id="site-name" name="name" value="{{ old('name') }}" required maxlength="255" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="Enter a site name">
                        @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="site-location" class="block text-sm font-medium text-gray-700">Location <span class="font-normal text-gray-400">(optional)</span></label>
                        <input id="site-location" name="location" value="{{ old('location') }}" maxlength="255" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="City, district, or address">
                        @error('location') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex justify-end gap-2 border-t border-gray-100 pt-4">
                        <button type="button" @click="addSiteOpen = false" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</button>
                        <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Create site</button>
                    </div>
                </form>
            </section>
        </div>
    </div>
</x-app-layout>
