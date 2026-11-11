<x-app-layout>
    <div class="py-8 bg-[#F8FAFC] min-h-screen" x-data="{ 
        showOverlay: false, 
        editMode: false, 
        packageId: null, 
        currency: @js($currency),
        displayCurrency() {
            return this.editMode ? (this.form.currency || this.currency) : this.currency;
        },
        form: { 
            name: '', 
            price: '', 
            duration_value: '', 
            duration_unit: 'days', 
            speed_limit_mbps: 5, 
            device_limit: 1, 
            data_limit: 'Unlimited', 
            features: [], 
            is_published: true,
            is_popular: false 
        },
        openCreate() {
            this.editMode = false; 
            this.packageId = null;
            this.form = { 
                name: '', 
                price: '', 
                duration_value: '', 
                duration_unit: 'days', 
                speed_limit_mbps: 5, 
                device_limit: 1, 
                data_limit: 'Unlimited', 
                features: ['Hotspot'], 
                is_published: true,
                is_popular: false 
            };
            this.showOverlay = true;
        },
        openEdit(pkg) {
            this.editMode = true; 
            this.packageId = pkg.id;
            this.form = { 
                ...pkg, 
                features: pkg.features || [],
                is_published: Boolean(pkg.is_published),
                is_popular: Boolean(pkg.is_popular)
            };
            this.showOverlay = true;
        }
    }">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            
            <!-- Flash Message -->
            @if(session('status'))
                <div class="mb-6 bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 rounded-xl flex justify-between items-center text-sm shadow-sm">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span>{{ session('status') }}</span>
                    </div>
                    <button type="button" @click="$el.parentElement.remove()" class="text-emerald-500 hover:text-emerald-700">&times;</button>
                </div>
            @endif

            <!-- ─── HEADER & ACTIONS ─── -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-7">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900">
                        Internet packages
                    </h1>
                    <span class="sr-only">Internet Packages</span>
                    <p class="text-xs sm:text-sm text-slate-500 mt-1">
                        Simple plans for your customers. Consistent speed and validity across every site.
                    </p>
                </div>
                <div class="flex items-center gap-2.5">
                    <button type="button" class="inline-flex items-center gap-2 px-3.5 py-2 text-xs font-semibold text-slate-700 bg-white border border-slate-200/90 rounded-lg hover:bg-slate-50 shadow-sm transition-colors">
                        <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
                        </svg>
                        <span>RouterOS profiles</span>
                    </button>
                    <button @click="openCreate()" type="button" class="inline-flex items-center gap-1.5 px-3.5 py-2 text-xs font-semibold text-white bg-blue-600 hover:bg-blue-700 rounded-lg shadow-sm shadow-blue-500/20 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                        </svg>
                        <span>Create package</span>
                    </button>
                </div>
            </div>

            <!-- ─── TOP KPI CARDS ─── -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-7">
                <!-- Published Packages -->
                <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm flex flex-col justify-between">
                    <div class="flex items-start justify-between">
                        <p class="text-xs font-medium text-slate-500">Published packages</p>
                        <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                        </svg>
                    </div>
                    <div class="mt-3">
                        <p class="text-3xl font-extrabold text-slate-900 tracking-tight">{{ $publishedCount }}</p>
                        <p class="text-[11px] font-semibold text-emerald-600 mt-2 flex items-center gap-1">
                            Available at all sites
                        </p>
                    </div>
                </div>

                <!-- Active Subscriptions -->
                <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm flex flex-col justify-between">
                    <div class="flex items-start justify-between">
                        <p class="text-xs font-medium text-slate-500">Active subscriptions</p>
                        <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                    </div>
                    <div class="mt-3">
                        <p class="text-3xl font-extrabold text-slate-900 tracking-tight">{{ number_format($activeSubscriptions) }}</p>
                        <p class="text-[11px] font-semibold text-emerald-600 mt-2 flex items-center gap-1">
                            Across all published packages
                        </p>
                    </div>
                </div>

                <!-- Most Popular -->
                <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm flex flex-col justify-between">
                    <div class="flex items-start justify-between">
                        <p class="text-xs font-medium text-slate-500">Most popular</p>
                        <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/>
                        </svg>
                    </div>
                    <div class="mt-3">
                        <p class="text-2xl font-bold text-slate-900 tracking-tight truncate">
                            {{ $mostPopular ? $mostPopular->name : '—' }}
                        </p>
                        <p class="text-[11px] font-semibold text-emerald-600 mt-2 flex items-center gap-1">
                            {{ $mostPopular ? 'Top tier selection' : 'No packages yet' }}
                        </p>
                    </div>
                </div>

                <!-- Tax Treatment -->
                <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm flex flex-col justify-between">
                    <div class="flex items-start justify-between">
                        <p class="text-xs font-medium text-slate-500">Tax treatment</p>
                        <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/>
                        </svg>
                    </div>
                    <div class="mt-3">
                        <p class="text-2xl font-bold text-slate-900 tracking-tight">VAT inclusive</p>
                        <p class="text-[11px] font-medium text-slate-400 mt-2">
                            18% • charged in {{ $currency }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- ─── PACKAGE CARDS GRID ─── -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
                @forelse($packages as $package)
                    @php
                        $nameLower = strtolower($package->name);
                        $isHome = str_contains($nameLower, 'home') || str_contains($nameLower, 'monthly');
                        $isBusiness = str_contains($nameLower, 'business') || str_contains($nameLower, 'plus') || str_contains($nameLower, 'corp');
                    @endphp

                    <div class="bg-white rounded-2xl p-5 flex flex-col justify-between relative transition-all duration-200 {{ $package->is_popular ? 'border-2 border-blue-500 ring-4 ring-blue-500/10 shadow-sm' : 'border border-slate-200 shadow-sm hover:shadow-md' }}">
                        
                        <div>
                            <!-- Card Header: Icon & Badge -->
                            <div class="flex items-center justify-between">
                                <div class="w-8 h-8 rounded-lg flex items-center justify-center text-blue-500">
                                    @if($isHome)
                                        <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                                        </svg>
                                    @elseif($isBusiness)
                                        <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                                        </svg>
                                    @else
                                        <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/>
                                        </svg>
                                    @endif
                                </div>

                                @if($package->is_popular)
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-blue-50 text-blue-600 border border-blue-200/80">
                                        <span class="w-1.5 h-1.5 rounded-full bg-blue-600"></span>
                                        Popular
                                    </span>
                                @elseif($package->is_published)
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/80">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-600"></span>
                                        Published
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-slate-100 text-slate-600 border border-slate-200">
                                        <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                                        Draft
                                    </span>
                                @endif
                            </div>

                            <!-- Package Name -->
                            <h3 class="text-base font-semibold text-slate-900 mt-3">
                                {{ $package->name }}
                            </h3>

                            <!-- Price -->
                            <div class="mt-2">
                                <div class="text-3xl font-extrabold text-slate-900 tracking-tight">
                                    {{ number_format($package->price) }}
                                </div>
                                <p class="text-xs text-slate-500 font-medium mt-0.5">
                                    {{ $package->currency }} / {{ $package->duration_value }} {{ Str::plural($package->duration_unit, $package->duration_value) }} • VAT inclusive
                                </p>
                            </div>

                            <!-- Feature list -->
                            <ul class="mt-5 space-y-2.5 text-xs text-slate-600">
                                <li class="flex items-center gap-2">
                                    <svg class="w-3.5 h-3.5 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                                    </svg>
                                    <span>{{ $package->speed_limit_mbps }} Mbps speed</span>
                                </li>
                                <li class="flex items-center gap-2">
                                    <svg class="w-3.5 h-3.5 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                                    </svg>
                                    <span>{{ $package->device_limit }} {{ Str::plural('device', $package->device_limit) }} simultaneously</span>
                                </li>
                                <li class="flex items-center gap-2">
                                    <svg class="w-3.5 h-3.5 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                                    </svg>
                                    <span>{{ $package->data_limit ?? 'Unlimited data' }}</span>
                                </li>

                                @if($package->features && is_array($package->features))
                                    @foreach($package->features as $feature)
                                        <li class="flex items-center gap-2">
                                            <svg class="w-3.5 h-3.5 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                                            </svg>
                                            <span>{{ $feature }}</span>
                                        </li>
                                    @endforeach
                                @endif
                            </ul>
                        </div>

                        <!-- Card Footer -->
                        <div class="mt-6">
                            <p class="text-[11px] text-slate-400 pb-3 border-b border-slate-100">
                                0 active subscriptions
                            </p>
                            <button @click="openEdit({{ $package->toJson() }})" type="button" class="mt-3 w-full bg-white hover:bg-slate-50 border border-slate-200 text-slate-700 text-xs font-semibold py-2 px-3 rounded-lg flex items-center justify-center gap-1.5 shadow-sm transition-colors">
                                <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                                </svg>
                                <span>Edit package</span>
                            </button>
                        </div>
                    </div>
                @empty
                    <div class="col-span-full bg-white rounded-2xl border border-dashed border-slate-300 p-12 text-center">
                        <svg class="w-12 h-12 text-slate-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                        <h4 class="text-sm font-semibold text-slate-800">No internet packages found</h4>
                        <p class="text-xs text-slate-500 mt-1 mb-4">Get started by creating your first package plan.</p>
                        <button @click="openCreate()" type="button" class="inline-flex items-center gap-1.5 px-3.5 py-2 text-xs font-semibold text-white bg-blue-600 hover:bg-blue-700 rounded-lg shadow-sm">
                            + Create package
                        </button>
                    </div>
                @endforelse
            </div>

            <!-- ─── NETWORK POLICY MAPPING TABLE ─── -->
            <div class="bg-white rounded-2xl border border-slate-200/90 shadow-sm overflow-hidden mb-6">
                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900">Network policy mapping</h3>
                        <p class="text-xs text-slate-500 mt-0.5">Profile updates sync to RADIUS and connected MikroTik routers.</p>
                    </div>
                    <button type="button" class="text-slate-400 hover:text-slate-600 p-1 rounded-md">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                            <circle cx="5" cy="12" r="2"/>
                            <circle cx="12" cy="12" r="2"/>
                            <circle cx="19" cy="12" r="2"/>
                        </svg>
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100 text-left text-xs">
                        <thead class="bg-slate-50/70 text-slate-500 font-semibold">
                            <tr>
                                <th class="w-10 px-6 py-3">
                                    <input type="checkbox" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500 w-3.5 h-3.5">
                                </th>
                                <th class="px-4 py-3">
                                    <span class="inline-flex items-center gap-1">
                                        Package
                                        <svg class="w-3 h-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                    </span>
                                </th>
                                <th class="px-4 py-3">RouterOS profile</th>
                                <th class="px-4 py-3">Rate limit ↓ / ↑</th>
                                <th class="px-4 py-3">Validity</th>
                                <th class="px-4 py-3">Sites</th>
                                <th class="px-4 py-3">Sync</th>
                                <th class="w-10 px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-600">
                            @forelse($packages as $package)
                                <tr class="hover:bg-slate-50/60 transition-colors">
                                    <td class="px-6 py-3.5">
                                        <input type="checkbox" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500 w-3.5 h-3.5">
                                    </td>
                                    <td class="px-4 py-3.5 font-medium text-slate-900">
                                        {{ $package->name }}
                                    </td>
                                    <td class="px-4 py-3.5 font-mono text-[11px] text-slate-600">
                                        {{ $package->routerProfileName() }}
                                    </td>
                                    <td class="px-4 py-3.5 text-slate-600">
                                        {{ $package->speed_limit_mbps }} Mbps
                                    </td>
                                    <td class="px-4 py-3.5 text-slate-600">
                                        {{ $package->duration_value }} {{ $package->duration_unit }}
                                    </td>
                                    <td class="px-4 py-3.5 text-slate-600">
                                        All sites
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-slate-50 text-slate-600 border border-slate-200/70" title="Check Router Settings for per-router sync results">
                                            Per-router status
                                        </span>
                                    </td>
                                    <td class="px-6 py-3.5 text-right relative" x-data="{ open: false }">
                                        <button @click="open = !open" type="button" class="text-slate-400 hover:text-slate-600 p-1 rounded-md focus:outline-none">
                                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                                <circle cx="5" cy="12" r="2"/>
                                                <circle cx="12" cy="12" r="2"/>
                                                <circle cx="19" cy="12" r="2"/>
                                            </svg>
                                        </button>
                                        <div x-show="open" 
                                             @click.away="open = false" 
                                             x-transition:enter="transition ease-out duration-100"
                                             x-transition:enter-start="transform opacity-0 scale-95"
                                             x-transition:enter-end="transform opacity-100 scale-100"
                                             x-transition:leave="transition ease-in duration-75"
                                             x-transition:leave-start="transform opacity-100 scale-100"
                                             x-transition:leave-end="transform opacity-0 scale-95"
                                             class="absolute right-6 mt-1.5 w-44 bg-white rounded-xl shadow-lg border border-slate-100 py-1.5 z-50 text-left"
                                             x-cloak>
                                            <button type="button" 
                                                    @click="open = false; openEdit({{ $package->toJson() }})" 
                                                    class="w-full text-left px-3.5 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 flex items-center gap-2 transition-colors">
                                                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                                                Edit package
                                            </button>
                                            <button type="button" 
                                                    @click="open = false; openCreate(); form = { ...{{ $package->toJson() }}, name: '{{ $package->name }} (Copy)', is_published: false }" 
                                                    class="w-full text-left px-3.5 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 flex items-center gap-2 transition-colors">
                                                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"></path></svg>
                                                Duplicate plan
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-6 py-8 text-center text-slate-400 text-xs">
                                        No package network mappings available.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="px-6 py-3 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-400">
                    <span>Showing 1-{{ count($packages) }} of {{ count($packages) }} records</span>
                    <span>All records displayed</span>
                </div>
            </div>

            <!-- ─── BOTTOM INFO BANNER ─── -->
            <div class="bg-blue-50/70 border border-blue-200/80 rounded-2xl p-4 flex items-start gap-3.5 mb-10">
                <div class="w-5 h-5 rounded-full border border-blue-400 text-blue-600 flex items-center justify-center font-bold text-xs shrink-0 mt-0.5">
                    i
                </div>
                <div>
                    <h4 class="text-xs font-semibold text-blue-900">
                        Changes are safe for existing customers
                    </h4>
                    <p class="text-xs text-blue-700/80 mt-0.5">
                        Price and validity changes apply to new purchases only. Active subscriptions retain their original terms.
                    </p>
                </div>
            </div>
        </div>

        <!-- ─── SLIDE-OVER OVERLAY (CREATE / EDIT PACKAGE) ─── -->
        <div x-show="showOverlay" class="relative z-50" aria-labelledby="slide-over-title" role="dialog" aria-modal="true" x-cloak>
            
            <!-- Backdrop -->
            <div x-show="showOverlay" 
                 x-transition:enter="ease-in-out duration-300" 
                 x-transition:enter-start="opacity-0" 
                 x-transition:enter-end="opacity-100" 
                 x-transition:leave="ease-in-out duration-300" 
                 x-transition:leave-start="opacity-100" 
                 x-transition:leave-end="opacity-0" 
                 class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity" 
                 @click="showOverlay = false"></div>

            <div class="fixed inset-0 overflow-hidden">
                <div class="absolute inset-0 overflow-hidden">
                    <div class="pointer-events-none fixed inset-y-0 right-0 flex max-w-full pl-10">
                        
                        <!-- Sliding Panel -->
                        <div x-show="showOverlay" 
                             x-transition:enter="transform transition ease-in-out duration-300 sm:duration-400" 
                             x-transition:enter-start="translate-x-full" 
                             x-transition:enter-end="translate-x-0" 
                             x-transition:leave="transform transition ease-in-out duration-300 sm:duration-400" 
                             x-transition:leave-start="translate-x-0" 
                             x-transition:leave-end="translate-x-full" 
                             class="pointer-events-auto w-screen max-w-md">
                            
                            <div class="flex h-full flex-col overflow-y-scroll bg-white shadow-2xl">
                                
                                <!-- Drawer Header -->
                                <div class="px-6 py-5 bg-slate-50 border-b border-slate-200">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <h2 class="text-base font-bold text-slate-900" x-text="editMode ? 'Edit package' : 'Create new package'"></h2>
                                            <p class="text-xs text-slate-500 mt-0.5" x-text="editMode ? 'Update limits and pricing for this package' : 'Configure bandwidth, quotas, and pricing'"></p>
                                        </div>
                                        <button @click="showOverlay = false" type="button" class="text-slate-400 hover:text-slate-600 rounded-lg p-1.5 hover:bg-slate-100 transition-colors">
                                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>

                                <!-- Drawer Form -->
                                <div class="relative flex-1 px-6 py-6">
                                    <form :action="editMode ? `/operator/packages/${packageId}` : '{{ route('operator.packages.store') }}'" method="POST" id="packageForm">
                                        @csrf
                                        <template x-if="editMode">
                                            <input type="hidden" name="_method" value="PUT">
                                        </template>

                                        <div class="space-y-4 text-xs">
                                            <!-- Name -->
                                            <div>
                                                <label class="block font-medium text-slate-700 mb-1">Package Name</label>
                                                <input type="text" name="name" x-model="form.name" placeholder="e.g. Daily Express" required 
                                                       class="block w-full rounded-lg border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                            </div>

                                            <!-- Price & Currency -->
                                            <div class="grid grid-cols-2 gap-3">
                                                <div>
                                                    <label class="block font-medium text-slate-700 mb-1">Price</label>
                                                    <input type="number" step="0.01" name="price" x-model="form.price" placeholder="2000" required 
                                                           class="block w-full rounded-lg border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                </div>
                                                <div>
                                                    <label class="block font-medium text-slate-700 mb-1">Currency</label>
                                                    <input type="text" :value="displayCurrency()" disabled
                                                           class="block w-full rounded-lg border-slate-200 bg-slate-50 text-xs shadow-sm text-slate-500">
                                                </div>
                                            </div>

                                            <!-- Duration -->
                                            <div class="grid grid-cols-2 gap-3">
                                                <div>
                                                    <label class="block font-medium text-slate-700 mb-1">Duration Value</label>
                                                    <input type="number" name="duration_value" x-model="form.duration_value" placeholder="24" required 
                                                           class="block w-full rounded-lg border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                </div>
                                                <div>
                                                    <label class="block font-medium text-slate-700 mb-1">Unit</label>
                                                    <select name="duration_unit" x-model="form.duration_unit" 
                                                            class="block w-full rounded-lg border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                        <option value="minutes">Minutes</option>
                                                        <option value="hours">Hours</option>
                                                        <option value="days">Days</option>
                                                        <option value="weeks">Weeks</option>
                                                        <option value="months">Months</option>
                                                        <option value="years">Years</option>
                                                    </select>
                                                </div>
                                            </div>

                                            <!-- Speed & Devices -->
                                            <div class="grid grid-cols-2 gap-3">
                                                <div>
                                                    <label class="block font-medium text-slate-700 mb-1">Speed Limit (Mbps)</label>
                                                    <input type="number" name="speed_limit_mbps" x-model="form.speed_limit_mbps" required 
                                                           class="block w-full rounded-lg border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                </div>
                                                <div>
                                                    <label class="block font-medium text-slate-700 mb-1">Device Limit</label>
                                                    <input type="number" name="device_limit" x-model="form.device_limit" required 
                                                           class="block w-full rounded-lg border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                </div>
                                            </div>

                                            <!-- Data Limit -->
                                            <div>
                                                <label class="block font-medium text-slate-700 mb-1">Data Limit</label>
                                                <input type="text" name="data_limit" x-model="form.data_limit" placeholder="e.g. Unlimited, 500 MB or 10 GB" 
                                                       class="block w-full rounded-lg border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                            </div>
                                            <p class="text-xs text-slate-500">The RouterOS profile name is generated consistently from this package's ID.</p>

                                            <!-- Features Checkboxes -->
                                            <div>
                                                <label class="block font-medium text-slate-700 mb-2">Service Features</label>
                                                <div class="space-y-2 bg-slate-50 p-3 rounded-xl border border-slate-200/80">
                                                    <label class="inline-flex items-center gap-2 cursor-pointer">
                                                        <input type="checkbox" name="features[]" value="Hotspot" x-model="form.features" 
                                                               class="rounded border-slate-300 text-blue-600 shadow-sm focus:ring-blue-500">
                                                        <span class="text-xs text-slate-700">Hotspot</span>
                                                    </label><br>
                                                    <label class="inline-flex items-center gap-2 cursor-pointer">
                                                        <input type="checkbox" name="features[]" value="PPPoE" x-model="form.features" 
                                                               class="rounded border-slate-300 text-blue-600 shadow-sm focus:ring-blue-500">
                                                        <span class="text-xs text-slate-700">PPPoE</span>
                                                    </label><br>
                                                    <label class="inline-flex items-center gap-2 cursor-pointer">
                                                        <input type="checkbox" name="features[]" value="Unlimited data" x-model="form.features" 
                                                               class="rounded border-slate-300 text-blue-600 shadow-sm focus:ring-blue-500">
                                                        <span class="text-xs text-slate-700">Unlimited data</span>
                                                    </label>
                                                </div>
                                            </div>
                                            
                                            <!-- Popular & Published Toggles -->
                                            <div class="pt-2 space-y-2.5">
                                                <div class="flex items-center">
                                                    <input type="hidden" name="is_popular" value="0">
                                                    <input type="checkbox" id="is_popular" name="is_popular" value="1" x-model="form.is_popular" 
                                                           class="rounded border-slate-300 text-blue-600 shadow-sm focus:ring-blue-500">
                                                    <label for="is_popular" class="ml-2 text-xs font-medium text-slate-800 cursor-pointer">
                                                        Mark as Popular package (Highlighted card)
                                                    </label>
                                                </div>

                                                <div class="flex items-center">
                                                    <input type="hidden" name="is_published" value="0">
                                                    <input type="checkbox" id="is_published" name="is_published" value="1" x-model="form.is_published" 
                                                           class="rounded border-slate-300 text-blue-600 shadow-sm focus:ring-blue-500">
                                                    <label for="is_published" class="ml-2 text-xs font-medium text-slate-800 cursor-pointer">
                                                        Publish immediately on captive portal
                                                    </label>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="mt-8 pt-4 border-t border-slate-200 flex gap-2.5">
                                            <button type="button" @click="showOverlay = false" 
                                                    class="flex-1 bg-white border border-slate-200 text-slate-700 py-2.5 rounded-lg text-xs font-semibold hover:bg-slate-50 transition-colors shadow-sm">
                                                Cancel
                                            </button>
                                            <button type="submit" 
                                                    class="flex-1 bg-blue-600 text-white py-2.5 rounded-lg text-xs font-semibold hover:bg-blue-700 shadow-sm shadow-blue-500/20 transition-colors">
                                                <span x-text="editMode ? 'Save Changes' : 'Create Package'"></span>
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
        <!-- ─── END SLIDE-OVER OVERLAY ─── -->

    </div>
</x-app-layout>
