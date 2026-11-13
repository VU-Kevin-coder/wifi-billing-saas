<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Platform Administration
        </h2>
    </x-slot>

    <div class="py-8 bg-gray-50 min-h-screen" x-data="{
        showCreateOverlay: {{ $errors->any() ? 'true' : 'false' }},
        name: '{{ old('name', '') }}',
        slug: '{{ old('slug', '') }}',
        slugManual: false,
        status: '{{ old('status', 'trial') }}',
        owner_name: '{{ old('owner_name', '') }}',
        owner_email: '{{ old('owner_email', '') }}',
        updateSlug() {
            if (!this.slugManual) {
                this.slug = this.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)+/g, '');
            }
        }
    }">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            
            @if(session('success'))
                <div class="mb-6 bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 rounded-xl flex items-center justify-between shadow-sm">
                    <div class="flex items-center gap-2.5">
                        <svg class="w-5 h-5 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span class="text-sm font-semibold">{{ session('success') }}</span>
                    </div>
                    <button type="button" @click="$el.closest('div').remove()" class="text-emerald-600 hover:text-emerald-800 text-lg leading-none font-bold px-1">&times;</button>
                </div>
            @endif

            <!-- Page Header -->
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Operator tenants</h1>
                    <p class="text-gray-500 text-sm mt-1">Manage operator organizations, platform plans and account lifecycle. Not an operator workspace.</p>
                </div>
                <div class="flex gap-2">
                    <button type="button" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium shadow-sm hover:bg-gray-50 transition-colors">Export tenants</button>
                    <button @click="showCreateOverlay = true" type="button" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium shadow-sm flex items-center gap-2 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                        Create operator tenant
                    </button>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <p class="text-sm font-medium text-gray-500">Operator tenants</p>
                    <p class="text-3xl font-bold text-gray-900 mt-2">{{ $totalOperators }}</p>
                    <p class="text-xs text-gray-500 mt-1">
                        {{ $activeOperators }} active
                        @if($trialsInProgress > 0) • {{ $trialsInProgress }} trial @endif
                        @if($suspendedOperators > 0) • {{ $suspendedOperators }} suspended @endif
                    </p>
                </div>
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <p class="text-sm font-medium text-gray-500">Trials in progress</p>
                    <p class="text-3xl font-bold text-gray-900 mt-2">{{ $trialsInProgress }}</p>
                    <p class="text-xs text-gray-500 mt-1">
                        @if($trialsEndingSoon > 0)
                            {{ $trialsEndingSoon }} {{ Str::plural('trial', $trialsEndingSoon) }} end in the next 7 days
                        @else
                            No trials ending soon
                        @endif
                    </p>
                </div>
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <p class="text-sm font-medium text-gray-500">Managed MikroTik routers</p>
                    <p class="text-3xl font-bold text-gray-900 mt-2">{{ number_format($managedRouters) }}</p>
                    <p class="text-xs text-gray-500 mt-1">
                        {{ number_format($onlineRouters) }} online
                        @if($offlineRouters > 0) • {{ number_format($offlineRouters) }} offline @endif
                    </p>
                </div>
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <p class="text-sm font-medium text-gray-500">Operator end-customers</p>
                    <p class="text-3xl font-bold text-gray-900 mt-2">{{ number_format($endCustomers) }}</p>
                    <p class="text-xs text-gray-500 mt-1">Aggregate across {{ $totalOperators }} tenant {{ Str::plural('workspace', $totalOperators) }}</p>
                </div>
            </div>

            <!-- Warning Banner (only show if there are suspended tenants) -->
            @if($suspendedOperators > 0)
            <div class="bg-orange-50 border border-orange-200 rounded-xl p-4 flex items-start gap-3 mb-6">
                <svg class="w-5 h-5 text-orange-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                <div class="flex-1">
                    <p class="text-sm font-medium text-orange-800">{{ $suspendedOperators }} operator {{ Str::plural('tenant', $suspendedOperators) }} suspended</p>
                    <p class="text-xs text-orange-700 mt-1">Review account restrictions before taking further action.</p>
                </div>
                <button class="text-sm font-medium text-orange-600 hover:text-orange-800 shrink-0">Review billing &rarr;</button>
            </div>
            @endif

            <!-- Filters -->
            <div class="bg-white rounded-t-xl border border-gray-200 border-b-0 p-4 flex gap-3 items-center">
                <div class="relative flex-1">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    </div>
                    <input type="text" placeholder="Search operator name, ID or owner" class="block w-full pl-10 pr-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-blue-500 bg-gray-50">
                </div>
                <select class="border border-gray-200 rounded-lg text-sm py-2 pl-3 pr-8 text-gray-600 bg-gray-50">
                    <option>All tenant states</option>
                </select>
                <select class="border border-gray-200 rounded-lg text-sm py-2 pl-3 pr-8 text-gray-600 bg-gray-50">
                    <option value="">All plans</option>
                    @foreach($plans ?? [] as $p)
                        <option value="{{ $p->id }}">{{ $p->name }}</option>
                    @endforeach
                </select>
                <select class="border border-gray-200 rounded-lg text-sm py-2 pl-3 pr-8 text-gray-600 bg-gray-50">
                    <option>Uganda</option>
                </select>
                <button class="ml-auto text-sm font-medium text-gray-600 hover:text-gray-900 flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path></svg>
                    More filters
                </button>
            </div>

            <!-- Main Table -->
            <div class="bg-white rounded-b-xl shadow-sm border border-gray-200 overflow-x-auto mb-8">
                <div class="px-4 py-3 border-b border-gray-100 flex justify-between items-center bg-gray-50">
                    <span class="text-sm font-bold text-gray-700">Operator directory</span>
                    <span class="text-xs text-gray-500">{{ $tenants->total() }} {{ Str::plural('organization', $tenants->total()) }}. Tenant access requires a platform role and is fully audited.</span>
                </div>
                
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-white">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Operator tenant ↓</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Owner / operators</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Plan / monthly fee</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Customer usage</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Routers online</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Tenant health</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Account state</th>
                            <th class="relative px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100">
                        @foreach($tenants as $tenant)
                            <tr class="hover:bg-gray-50 cursor-pointer">
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <a href="{{ route('platform.tenants.show', $tenant) }}" class="block group">
                                        <div class="text-sm font-bold text-blue-600 group-hover:underline">{{ $tenant->name }}</div>
                                        <div class="text-xs text-gray-500 font-mono">{{ $tenant->slug }}</div>
                                    </a>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">{{ $tenant->users->first()->name ?? 'N/A' }}</div>
                                    <div class="text-xs text-gray-500">{{ $tenant->staff_count }} {{ Str::plural('operator', $tenant->staff_count) }}</div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <div class="text-sm font-semibold text-gray-900">
                                        {{ $tenant->plan?->name ?? (is_string($tenant->plan) ? $tenant->plan : ($tenant->getRawOriginal('plan') ?? 'No plan')) }}
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        @if($tenant->plan && $tenant->plan->price > 0)
                                            UGX {{ number_format($tenant->plan->price) }} / month
                                        @elseif($tenant->plan_fee > 0)
                                            UGX {{ number_format($tenant->plan_fee) }} / month
                                        @else
                                            Custom / Trial
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900 font-medium">{{ $tenant->package_usage }}</div>
                                    <div class="flex items-center gap-1 mt-1">
                                        <div class="w-16 h-1.5 bg-gray-200 rounded-full overflow-hidden">
                                            <div class="h-full bg-blue-500 rounded-full" style="width: {{ min($tenant->package_percentage, 100) }}%"></div>
                                        </div>
                                        <span class="text-[10px] text-gray-500">{{ $tenant->package_percentage }}%</span>
                                    </div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900 font-medium">{{ $tenant->router_health }}</div>
                                    <div class="text-xs {{ $tenant->router_healthy ? 'text-green-600' : 'text-orange-600' }}">
                                        {{ $tenant->router_healthy ? 'Healthy' : 'Attention' }}
                                    </div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    @if($tenant->router_healthy)
                                        <span class="inline-flex items-center gap-1 text-xs font-bold text-green-600 bg-green-50 px-2 py-1 rounded">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                            Healthy
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 text-xs font-bold text-orange-600 bg-orange-50 px-2 py-1 rounded">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                                            Attention
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    @if($tenant->account_status === 'Trial')
                                        <span class="text-xs font-bold text-blue-600 flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-blue-600"></span> Trial</span>
                                    @elseif($tenant->status === 'suspended')
                                        <span class="text-xs font-bold text-red-600 flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-red-500"></span> Suspended</span>
                                    @else
                                        <span class="text-xs font-bold text-green-600 flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-green-600"></span> Active</span>
                                    @endif
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-right">
                                    <div class="flex items-center justify-end gap-2" x-data="{ open: false }">
                                        <!-- Switch into button -->
                                        <form method="POST" action="{{ route('platform.impersonate', $tenant) }}" class="inline">
                                            @csrf
                                            <button type="submit"
                                               class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold text-blue-600 bg-blue-50 hover:bg-blue-100 rounded-lg transition-colors"
                                               title="Access operator workspace">
                                                <span>Switch into</span>
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                                            </button>
                                        </form>
                                        <!-- More actions dropdown -->
                                        <div class="relative">
                                            <button @click="open = !open" @click.outside="open = false"
                                                    class="p-1.5 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors">
                                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg>
                                            </button>
                                            <div x-show="open" x-cloak
                                                 x-transition:enter="transition ease-out duration-100"
                                                 x-transition:enter-start="opacity-0 scale-95"
                                                 x-transition:enter-end="opacity-100 scale-100"
                                                 x-transition:leave="transition ease-in duration-75"
                                                 x-transition:leave-start="opacity-100 scale-100"
                                                 x-transition:leave-end="opacity-0 scale-95"
                                                 class="absolute right-0 mt-1 w-44 bg-white rounded-xl shadow-lg border border-gray-100 py-1 z-20 text-left">
                                                <form method="POST" action="{{ route('platform.impersonate', $tenant) }}" class="w-full">
                                                    @csrf
                                                    <button type="submit"
                                                       class="flex w-full items-center gap-2 px-3 py-2 text-xs text-gray-700 hover:bg-gray-50 font-medium">
                                                        <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                                        Switch into workspace
                                                    </button>
                                                </form>
                                                <div class="border-t border-gray-100 my-1"></div>
                                                <button class="flex w-full items-center gap-2 px-3 py-2 text-xs text-gray-700 hover:bg-gray-50 font-medium">
                                                    <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                                    Edit tenant details
                                                </button>
                                                <button class="flex w-full items-center gap-2 px-3 py-2 text-xs text-gray-700 hover:bg-gray-50 font-medium">
                                                    <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                                    Change plan
                                                </button>
                                                <div class="border-t border-gray-100 my-1"></div>
                                                <button class="flex w-full items-center gap-2 px-3 py-2 text-xs text-red-600 hover:bg-red-50 font-medium">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                                    Suspend tenant
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                
                <div class="px-6 py-4 border-t border-gray-200 bg-gray-50">
                    {{ $tenants->links() }}
                </div>
            </div>

            <!-- Bottom Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
                    <div class="flex justify-between items-start mb-2">
                        <h4 class="text-sm font-bold text-gray-900">Upcoming lifecycle events</h4>
                        <button class="text-blue-600 text-sm font-medium hover:underline">View all events</button>
                    </div>
                    <p class="text-xs text-gray-500 mb-4">Actions that may affect operator account access.</p>
                    @php
                        $trialEvents = \App\Models\Tenant::whereBetween('created_at', [now()->subDays(14), now()])->orderBy('created_at')->take(3)->get();
                    @endphp
                    @if($trialEvents->isEmpty())
                        <p class="text-xs text-gray-400 italic">No upcoming lifecycle events.</p>
                    @else
                        <div class="space-y-3">
                            @foreach($trialEvents as $event)
                                @php $trialEnds = $event->created_at->addDays(14); @endphp
                                <div class="flex justify-between items-center">
                                    <div>
                                        <p class="text-sm font-medium text-blue-600">{{ $event->name }} — trial</p>
                                        <p class="text-xs text-gray-500">Ends in {{ now()->diffInDays($trialEnds, false) > 0 ? now()->diffInDays($trialEnds).' days' : 'today / overdue' }}</p>
                                    </div>
                                    <span class="text-xs font-bold text-gray-900 shrink-0 ml-4">{{ $trialEnds->format('d M') }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
                    <div class="flex justify-between items-start mb-2">
                        <h4 class="text-sm font-bold text-gray-900">Tenant access safeguards</h4>
                        <button class="text-blue-600 text-sm font-medium hover:underline">Access policy</button>
                    </div>
                    <p class="text-xs text-gray-500 mb-4">Platform support can't access operator workspaces without a scoped request.</p>
                    <div class="flex gap-2 mb-3">
                        <span class="bg-green-50 text-green-700 text-xs font-bold px-2 py-1 rounded">Requests required</span>
                        <span class="bg-green-50 text-green-700 text-xs font-bold px-2 py-1 rounded">10 minutes</span>
                        <span class="bg-green-50 text-green-700 text-xs font-bold px-2 py-1 rounded">Fully audited</span>
                    </div>
                    <p class="text-xs text-gray-400">Use the tenant menu to request support access. Plan changes and suspensions are recorded in the audit trail.</p>
                </div>
            </div>

        </div>

        <!-- ─── SLIDE-OVER DRAWER (CREATE OPERATOR TENANT) ─── -->
        <div x-show="showCreateOverlay" class="relative z-50" aria-labelledby="slide-over-title" role="dialog" aria-modal="true" x-cloak>
            
            <!-- Backdrop -->
            <div x-show="showCreateOverlay" 
                 x-transition:enter="ease-in-out duration-300" 
                 x-transition:enter-start="opacity-0" 
                 x-transition:enter-end="opacity-100" 
                 x-transition:leave="ease-in-out duration-300" 
                 x-transition:leave-start="opacity-100" 
                 x-transition:leave-end="opacity-0" 
                 class="fixed inset-0 bg-gray-900/40 backdrop-blur-sm transition-opacity" 
                 @click="showCreateOverlay = false"></div>

            <div class="fixed inset-0 overflow-hidden">
                <div class="absolute inset-0 overflow-hidden">
                    <div class="pointer-events-none fixed inset-y-0 right-0 flex max-w-full pl-10">
                        
                        <!-- Sliding Panel -->
                        <div x-show="showCreateOverlay" 
                             x-transition:enter="transform transition ease-in-out duration-300 sm:duration-400" 
                             x-transition:enter-start="translate-x-full" 
                             x-transition:enter-end="translate-x-0" 
                             x-transition:leave="transform transition ease-in-out duration-300 sm:duration-400" 
                             x-transition:leave-start="translate-x-0" 
                             x-transition:leave-end="translate-x-full" 
                             class="pointer-events-auto w-screen max-w-lg">
                            
                            <div class="flex h-full flex-col overflow-y-scroll bg-white shadow-2xl">
                                
                                <!-- Drawer Header -->
                                <div class="bg-gray-900 px-6 py-6 text-white sm:px-8">
                                    <div class="flex items-start justify-between">
                                        <div>
                                            <h2 class="text-base font-bold text-white flex items-center gap-2">
                                                <svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                                                Create Operator Tenant
                                            </h2>
                                            <p class="mt-1 text-xs text-gray-300">
                                                Provision a new ISP/Hotspot operator workspace and configure initial account credentials.
                                            </p>
                                        </div>
                                        <button type="button" @click="showCreateOverlay = false" 
                                                class="rounded-lg p-1.5 text-gray-400 hover:text-white hover:bg-gray-800 focus:outline-none transition-colors">
                                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>

                                <!-- Drawer Form -->
                                <div class="relative flex-1 px-6 py-6 sm:px-8">
                                    <form action="{{ route('platform.tenants.store') }}" method="POST">
                                        @csrf

                                        <div class="space-y-5 text-xs">
                                            
                                            <!-- Organization Details Section -->
                                            <div class="border-b border-gray-100 pb-4">
                                                <h3 class="text-xs font-bold text-gray-900 uppercase tracking-wider mb-3">Operator Organization</h3>
                                                
                                                <div class="space-y-4">
                                                    <!-- Tenant Name -->
                                                    <div>
                                                        <label for="tenant_name" class="block font-medium text-gray-700 mb-1">Company / Operator Name <span class="text-red-500">*</span></label>
                                                        <input type="text" id="tenant_name" name="name" x-model="name" @input="updateSlug()" required placeholder="e.g. Nile Internet Services"
                                                               class="block w-full rounded-lg border-gray-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                        @error('name')
                                                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                                        @enderror
                                                    </div>

                                                    <!-- Subdomain / Slug -->
                                                    <div>
                                                        <label for="tenant_slug" class="block font-medium text-gray-700 mb-1">Workspace Subdomain (Slug) <span class="text-red-500">*</span></label>
                                                        <div class="flex rounded-lg shadow-sm">
                                                            <input type="text" id="tenant_slug" name="slug" x-model="slug" @input="slugManual = true" required placeholder="nile-internet"
                                                                   class="block w-full min-w-0 flex-1 rounded-none rounded-l-lg border-gray-300 text-xs font-mono focus:border-blue-500 focus:ring-blue-500">
                                                            <span class="inline-flex items-center rounded-r-lg border border-l-0 border-gray-300 bg-gray-50 px-3 text-xs text-gray-500 font-mono">
                                                                .{{ \App\Models\Tenant::domainSuffix() }}
                                                            </span>
                                                        </div>
                                                        @error('slug')
                                                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                                        @enderror
                                                        <p class="text-[11px] text-gray-400 mt-1">This forms the captive portal and operator admin address.</p>
                                                    </div>

                                                    <div class="grid grid-cols-2 gap-3">
                                                        <!-- Plan -->
                                                        <div>
                                                            <label for="tenant_plan" class="block font-medium text-gray-700 mb-1">Subscription Plan</label>
                                                            <select id="tenant_plan" name="platform_plan_id"
                                                                    class="block w-full rounded-lg border-gray-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                                @forelse($plans ?? [] as $p)
                                                                    <option value="{{ $p->id }}">{{ $p->name }} (UGX {{ number_format($p->price) }}/mo)</option>
                                                                @empty
                                                                    <option value="">No plans defined yet</option>
                                                                @endforelse
                                                            </select>
                                                            @error('platform_plan_id')
                                                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                                            @enderror
                                                        </div>

                                                        <!-- Status -->
                                                        <div>
                                                            <label for="tenant_status" class="block font-medium text-gray-700 mb-1">Account State</label>
                                                            <select id="tenant_status" name="status" x-model="status"
                                                                    class="block w-full rounded-lg border-gray-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                                <option value="trial">14-Day Free Trial</option>
                                                                <option value="active">Active (Paid)</option>
                                                                <option value="suspended">Suspended</option>
                                                            </select>
                                                            @error('status')
                                                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                                            @enderror
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Primary Admin / Owner Section -->
                                            <div>
                                                <h3 class="text-xs font-bold text-gray-900 uppercase tracking-wider mb-3">Primary Operator Admin</h3>
                                                
                                                <div class="space-y-4">
                                                    <!-- Owner Name -->
                                                    <div>
                                                        <label for="owner_name" class="block font-medium text-gray-700 mb-1">Admin Full Name <span class="text-red-500">*</span></label>
                                                        <input type="text" id="owner_name" name="owner_name" x-model="owner_name" required placeholder="e.g. Dennis Okello"
                                                               class="block w-full rounded-lg border-gray-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                        @error('owner_name')
                                                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                                        @enderror
                                                    </div>

                                                    <!-- Owner Email -->
                                                    <div>
                                                        <label for="owner_email" class="block font-medium text-gray-700 mb-1">Admin Email Address <span class="text-red-500">*</span></label>
                                                        <input type="email" id="owner_email" name="owner_email" x-model="owner_email" required placeholder="dennis@example.com"
                                                               class="block w-full rounded-lg border-gray-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                        @error('owner_email')
                                                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                                        @enderror
                                                        <p class="text-[11px] text-gray-400 mt-1">Used to log in to the operator workspace.</p>
                                                    </div>

                                                    <p class="text-[11px] text-gray-500">The admin will receive an email invitation to set their own password.</p>
                                                </div>
                                            </div>

                                            <!-- Safeguards Notice Banner -->
                                            <div class="bg-blue-50 border border-blue-200 rounded-xl p-3 flex items-start gap-2.5">
                                                <svg class="w-4 h-4 text-blue-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                                <p class="text-[11px] text-blue-800 leading-relaxed">
                                                    Creating this tenant provisions isolated database scopes, default voucher settings, and records an audit log entry.
                                                </p>
                                            </div>

                                        </div>

                                        <div class="mt-8 pt-4 border-t border-gray-200 flex gap-3">
                                            <button type="button" @click="showCreateOverlay = false" 
                                                    class="flex-1 bg-white border border-gray-300 text-gray-700 py-2.5 rounded-lg text-xs font-semibold hover:bg-gray-50 transition-colors shadow-sm">
                                                Cancel
                                            </button>
                                            <button type="submit" 
                                                    class="flex-1 bg-blue-600 text-white py-2.5 rounded-lg text-xs font-semibold hover:bg-blue-700 shadow-sm shadow-blue-500/20 transition-colors">
                                                Create Tenant & Workspace
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
        <!-- ─── END SLIDE-OVER DRAWER ─── -->
    </div>
</x-app-layout>
