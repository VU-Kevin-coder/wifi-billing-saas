<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-2 text-sm text-gray-500">
            <a href="{{ route('platform.tenants.index') }}" class="hover:text-gray-900">Platform administration</a>
            <span>&rsaquo;</span>
            <a href="{{ route('platform.tenants.index') }}" class="hover:text-gray-900">Operator tenants</a>
            <span>&rsaquo;</span>
            <span class="text-gray-900 font-medium">{{ $tenant->name }}</span>
        </div>
    </x-slot>

    <div class="py-8 bg-gray-50 min-h-screen">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            <!-- Header -->
            <div class="flex justify-between items-start mb-6">
                <div>
                    <div class="flex items-center gap-3 mb-1">
                        <h1 class="text-2xl font-bold text-gray-900">{{ $tenant->name }}</h1>
                        @if($tenant->subscription_status === 'trial')
                            <span class="bg-blue-100 text-blue-700 text-xs font-bold px-2 py-0.5 rounded flex items-center gap-1">
                                <span class="w-1.5 h-1.5 rounded-full bg-blue-600"></span> Trial
                            </span>
                        @elseif($tenant->status === 'suspended')
                            <span class="bg-red-100 text-red-700 text-xs font-bold px-2 py-0.5 rounded flex items-center gap-1">
                                <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span> Suspended
                            </span>
                        @else
                            <span class="bg-green-100 text-green-700 text-xs font-bold px-2 py-0.5 rounded flex items-center gap-1">
                                <span class="w-1.5 h-1.5 rounded-full bg-green-600"></span> Active
                            </span>
                        @endif
                    </div>
                    @if($tenant->legal_name)
                        <p class="text-sm text-gray-500">{{ $tenant->legal_name }}</p>
                    @endif
                    <p class="text-xs text-gray-400 mt-1">Tenant ID: {{ $tenant->slug }} • Created {{ $tenant->created_at->format('d M Y') }}</p>
                </div>
                <div class="flex gap-2">
                    <a href="{{ route('platform.tenants.index') }}" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium shadow-sm hover:bg-gray-50 transition-colors">
                        ← Back to list
                    </a>
                    <a href="{{ route('platform.impersonate', $tenant) }}" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium shadow-sm flex items-center gap-2 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        Switch into workspace
                    </a>
                </div>
            </div>

            <!-- Tabs -->
            <div class="border-b border-gray-200 mb-6">
                <nav class="-mb-px flex space-x-8">
                    <a href="#overview" class="border-blue-500 text-blue-600 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">Overview</a>
                    <a href="#billing"  class="border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">Billing</a>
                    <a href="#infra"    class="border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">Infrastructure</a>
                    <a href="#team"     class="border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">Team</a>
                    <a href="#activity" class="border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">Activity</a>
                </nav>
            </div>

            <!-- Trial / Status Alert Banner -->
            @if($tenant->subscription_status === 'trial' && $tenant->trial_ends_at)
                <div class="bg-orange-50 border border-orange-200 rounded-xl p-4 flex items-start gap-3 mb-6">
                    <svg class="w-5 h-5 text-orange-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                    <div class="flex-1">
                        <p class="text-sm font-medium text-orange-800">{{ $tenant->name }} is on a free trial.</p>
                        <p class="text-xs text-orange-700 mt-1">
                            Trial ends <strong>{{ $tenant->trial_ends_at->format('d M Y') }}</strong>
                            ({{ $tenant->trial_ends_at->diffForHumans() }}).
                            Convert to a paid plan to keep the operator account active after the trial period.
                        </p>
                    </div>
                    <button class="text-sm font-medium text-orange-600 hover:text-orange-800 shrink-0">Convert to paid &rarr;</button>
                </div>
            @elseif($tenant->status === 'suspended')
                <div class="bg-red-50 border border-red-200 rounded-xl p-4 flex items-start gap-3 mb-6">
                    <svg class="w-5 h-5 text-red-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                    <div class="flex-1">
                        <p class="text-sm font-medium text-red-800">This tenant is suspended.</p>
                        <p class="text-xs text-red-700 mt-1">Operator workspace access is restricted. Restore the account to re-enable access.</p>
                    </div>
                    <button class="text-sm font-medium text-red-600 hover:text-red-800 shrink-0">Restore account &rarr;</button>
                </div>
            @endif

            <!-- KPI Cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6" id="overview">
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <p class="text-sm font-medium text-gray-500">End-customers</p>
                    <p class="text-3xl font-bold text-gray-900 mt-2">{{ number_format($customerCount) }}</p>
                    <p class="text-xs text-gray-500 mt-1">{{ $packageCount }} {{ Str::plural('package', $packageCount) }} defined</p>
                </div>
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <p class="text-sm font-medium text-gray-500">Staff / operators</p>
                    <p class="text-3xl font-bold text-gray-900 mt-2">{{ $staff->count() }}</p>
                    <p class="text-xs text-gray-500 mt-1">{{ $staff->where('roles.0.name', 'operator_admin')->count() }} admin • {{ $staff->count() - $staff->where('roles.0.name', 'operator_admin')->count() }} staff</p>
                </div>
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <p class="text-sm font-medium text-gray-500">Router availability</p>
                    <p class="text-3xl font-bold text-gray-900 mt-2">
                        {{ $routerCount > 0 ? number_format(($onlineRouters / $routerCount) * 100, 1) : '—' }}%
                    </p>
                    <p class="text-xs text-gray-500 mt-1">{{ $onlineRouters }} / {{ $routerCount }} routers online</p>
                </div>
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <p class="text-sm font-medium text-gray-500">Recent sessions</p>
                    <p class="text-3xl font-bold text-gray-900 mt-2">{{ $recentActivity->count() }}</p>
                    <p class="text-xs text-gray-500 mt-1">Latest {{ $recentActivity->count() }} logged</p>
                </div>
            </div>

            <!-- Info Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

                <!-- Account Information -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-base font-bold text-gray-900">Account information</h3>
                        <button class="text-blue-600 text-sm font-medium hover:underline">Edit details</button>
                    </div>
                    <p class="text-xs text-gray-500 mb-4">Operator organization and primary contact.</p>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-[11px] font-bold text-gray-400 uppercase tracking-wider">Primary contact</p>
                            <p class="text-sm font-medium text-gray-900 mt-1">{{ $tenant->contact_name ?? 'N/A' }}</p>
                            <p class="text-xs text-gray-500">Owner</p>
                        </div>
                        <div>
                            <p class="text-[11px] font-bold text-gray-400 uppercase tracking-wider">Location</p>
                            <p class="text-sm font-medium text-gray-900 mt-1">{{ $tenant->address ?? 'N/A' }}</p>
                        </div>
                        <div>
                            <p class="text-[11px] font-bold text-gray-400 uppercase tracking-wider">Email address</p>
                            <p class="text-sm font-medium text-gray-900 mt-1">
                                @if($tenant->contact_email)
                                    <a href="mailto:{{ $tenant->contact_email }}" class="text-blue-600 hover:underline">{{ $tenant->contact_email }}</a>
                                @else
                                    N/A
                                @endif
                            </p>
                        </div>
                        <div>
                            <p class="text-[11px] font-bold text-gray-400 uppercase tracking-wider">Phone number</p>
                            <p class="text-sm font-medium text-gray-900 mt-1">{{ $tenant->contact_phone ?? 'N/A' }}</p>
                        </div>
                        <div class="col-span-2">
                            <p class="text-[11px] font-bold text-gray-400 uppercase tracking-wider">Legal name</p>
                            <p class="text-sm font-medium text-gray-900 mt-1">{{ $tenant->legal_name ?? $tenant->name }}</p>
                        </div>
                    </div>
                </div>

                <!-- Plan & Billing -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6" id="billing">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-base font-bold text-gray-900">Plan & billing details</h3>
                        <button class="text-blue-600 text-sm font-medium hover:underline">View billing</button>
                    </div>

                    <div class="flex items-center justify-between mb-4 p-3 bg-gray-50 rounded-xl">
                        <div>
                            <p class="text-sm font-bold text-gray-900">{{ $tenant->plan ?? 'Growth' }} Plan</p>
                            <p class="text-xs text-gray-500">UGX {{ number_format($tenant->plan_fee ?? 0) }} / month</p>
                        </div>
                        @if($tenant->subscription_status === 'active')
                            <span class="bg-green-100 text-green-700 text-xs font-bold px-2 py-1 rounded">Active</span>
                        @elseif($tenant->subscription_status === 'trial')
                            <span class="bg-blue-100 text-blue-700 text-xs font-bold px-2 py-1 rounded">Trial</span>
                        @elseif($tenant->subscription_status === 'past_due')
                            <span class="bg-orange-100 text-orange-700 text-xs font-bold px-2 py-1 rounded">Past Due</span>
                        @else
                            <span class="bg-red-100 text-red-700 text-xs font-bold px-2 py-1 rounded">Suspended</span>
                        @endif
                    </div>

                    <div class="border-t border-gray-100 pt-4 space-y-3">
                        @if($tenant->subscription_ends_at)
                        <div class="flex justify-between items-center">
                            <div>
                                <p class="text-xs text-gray-500">Next invoice / renewal</p>
                                <p class="text-sm font-medium text-gray-900">{{ $tenant->subscription_ends_at->format('d M Y') }}</p>
                            </div>
                            <span class="text-xs font-bold text-gray-900">UGX {{ number_format($tenant->plan_fee ?? 0) }}</span>
                        </div>
                        @endif
                        @if($tenant->trial_ends_at)
                        <div class="flex justify-between items-center">
                            <div>
                                <p class="text-xs text-gray-500">Trial ends</p>
                                <p class="text-sm font-medium text-gray-900">{{ $tenant->trial_ends_at->format('d M Y') }}</p>
                            </div>
                            <span class="text-xs font-semibold {{ $tenant->trial_ends_at->isPast() ? 'text-red-600' : 'text-orange-600' }}">
                                {{ $tenant->trial_ends_at->diffForHumans() }}
                            </span>
                        </div>
                        @endif
                        <div class="flex justify-between items-center">
                            <p class="text-xs text-gray-500">Auto-renew</p>
                            <span class="text-xs font-bold {{ $tenant->auto_renew ? 'text-green-600' : 'text-gray-400' }}">
                                {{ $tenant->auto_renew ? 'Enabled' : 'Disabled' }}
                            </span>
                        </div>
                        <div class="flex justify-between items-center pt-1">
                            <span class="text-xs text-gray-500">Change plan</span>
                            <button class="text-blue-600 text-sm font-medium hover:underline">Change plan &rarr;</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Infrastructure & Team -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6" id="infra">

                <!-- Infrastructure Health -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-base font-bold text-gray-900">Infrastructure health</h3>
                        <a href="{{ route('platform.impersonate', $tenant) }}" class="text-blue-600 text-sm font-medium hover:underline">View routers</a>
                    </div>
                    <p class="text-xs text-gray-500 mb-4">Network and router health from the operator workspace.</p>

                    <div class="space-y-3">
                        <div class="flex justify-between items-center">
                            <div class="flex items-center gap-2 text-sm text-gray-700">
                                <span class="w-2 h-2 rounded-full {{ $onlineRouters > 0 ? 'bg-green-500' : 'bg-gray-300' }}"></span>
                                MikroTik connectivity
                            </div>
                            <span class="text-xs font-bold {{ $onlineRouters === $routerCount && $routerCount > 0 ? 'text-green-600' : ($onlineRouters > 0 ? 'text-orange-600' : 'text-gray-400') }}">
                                {{ $onlineRouters }} / {{ $routerCount }} online
                            </span>
                        </div>
                        <div class="flex justify-between items-center">
                            <div class="flex items-center gap-2 text-sm text-gray-700">
                                <span class="w-2 h-2 rounded-full bg-green-500"></span>
                                Hotspot service
                            </div>
                            <span class="text-xs font-bold text-green-600">Running</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <div class="flex items-center gap-2 text-sm text-gray-700">
                                <span class="w-2 h-2 rounded-full bg-green-500"></span>
                                RADIUS authentication
                            </div>
                            <span class="text-xs font-bold text-green-600">Running</span>
                        </div>
                    </div>
                </div>

                <!-- Team -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6" id="team">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-base font-bold text-gray-900">Operator team</h3>
                        <span class="text-xs text-gray-400">{{ $staff->count() }} {{ Str::plural('member', $staff->count()) }}</span>
                    </div>
                    <p class="text-xs text-gray-500 mb-4">Staff with access to this operator workspace.</p>

                    @if($staff->isEmpty())
                        <p class="text-xs text-gray-400 italic">No staff members found.</p>
                    @else
                        <div class="space-y-3">
                            @foreach($staff as $member)
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-700 text-xs font-bold flex items-center justify-center shrink-0">
                                            {{ strtoupper(substr($member->name, 0, 1)) }}{{ strtoupper(substr(explode(' ', $member->name)[1] ?? '', 0, 1)) }}
                                        </div>
                                        <div>
                                            <p class="text-sm font-medium text-gray-900">{{ $member->name }}</p>
                                            <p class="text-xs text-gray-500">{{ $member->email }}</p>
                                        </div>
                                    </div>
                                    <span class="text-[11px] font-semibold text-gray-500 bg-gray-100 px-2 py-0.5 rounded">
                                        {{ str_replace('operator_', '', $member->roles->first()?->name ?? 'staff') }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6" id="activity">
                <div class="flex justify-between items-center mb-4">
                    <div>
                        <h3 class="text-base font-bold text-gray-900">Recent sessions</h3>
                        <p class="text-xs text-gray-500">Latest hotspot sessions from this operator's network.</p>
                    </div>
                    <a href="{{ route('platform.impersonate', $tenant) }}" class="text-blue-600 text-sm font-medium hover:underline">View all sessions &rarr;</a>
                </div>

                @if($recentActivity->isEmpty())
                    <div class="text-center py-8 text-gray-400">
                        <svg class="w-10 h-10 mx-auto mb-2 text-gray-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        <p class="text-xs">No recent session activity found.</p>
                    </div>
                @else
                    <div class="divide-y divide-gray-100">
                        @foreach($recentActivity as $activity)
                            <div class="flex items-start gap-3 py-3">
                                <div class="w-8 h-8 rounded-full bg-blue-50 flex items-center justify-center shrink-0 mt-0.5">
                                    <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm text-gray-900">
                                        <span class="font-medium">{{ $activity->customer->name ?? 'Guest' }}</span>
                                        connected via
                                        <span class="font-medium">{{ $activity->package->name ?? 'Unknown package' }}</span>
                                    </p>
                                    <p class="text-xs text-gray-500">{{ $activity->started_at->diffForHumans() }} &bull; {{ $activity->ip_address ?? 'N/A' }}</p>
                                </div>
                                <span class="text-[11px] font-semibold px-2 py-0.5 rounded shrink-0 {{ $activity->status === 'active' ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                                    {{ ucfirst($activity->status ?? 'unknown') }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <!-- Access Safeguards -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex justify-between items-center mb-2">
                    <h3 class="text-base font-bold text-gray-900">Tenant management safeguards</h3>
                    <button class="text-blue-600 text-sm font-medium hover:underline">Access policy</button>
                </div>
                <p class="text-xs text-gray-500 mb-4">All platform-level admin actions on this tenant require a reason and are fully audited.</p>

                <div class="flex flex-wrap gap-2 mb-3">
                    <span class="bg-green-50 text-green-700 text-xs font-bold px-2.5 py-1 rounded flex items-center gap-1.5">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        Request required
                    </span>
                    <span class="bg-green-50 text-green-700 text-xs font-bold px-2.5 py-1 rounded flex items-center gap-1.5">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Fully audited
                    </span>
                    <span class="bg-green-50 text-green-700 text-xs font-bold px-2.5 py-1 rounded flex items-center gap-1.5">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                        Policy controls
                    </span>
                </div>
                <p class="text-xs text-gray-400">Plan changes, suspensions, and workspace access are recorded in the audit trail.</p>
            </div>

        </div>
    </div>
</x-app-layout>

