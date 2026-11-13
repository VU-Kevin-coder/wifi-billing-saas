<x-app-layout>
    <div class="py-8 bg-gray-50 min-h-screen">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- ── Header ───────────────────────────────────────────────── --}}
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">
                        Good {{ now()->hour < 12 ? 'morning' : (now()->hour < 18 ? 'afternoon' : 'evening') }}, Admin
                    </h1>
                    <p class="text-gray-500 text-sm mt-1">Here is what is happening across the platform today — {{ now()->format('l, d M Y') }}.</p>
                </div>
                <div class="flex gap-3">
                    <a href="{{ route('platform.tenants.index') }}"
                       class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium shadow-sm flex items-center gap-2 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Manage Tenants
                    </a>
                </div>
            </div>

            {{-- ── Row 1: Top Financial KPIs ────────────────────────────── --}}
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-4">

                {{-- Hero Card --}}
                <div class="lg:col-span-1 bg-gray-900 rounded-2xl p-6 text-white shadow-xl relative overflow-hidden flex flex-col justify-between min-h-[160px]">
                    <div class="absolute -right-10 -top-10 w-40 h-40 bg-blue-500 rounded-full opacity-20 blur-2xl pointer-events-none"></div>
                    <div>
                        <p class="text-gray-400 text-xs font-semibold uppercase tracking-wider">Platform Revenue ({{ now()->format('M Y') }})</p>
                        <h2 class="text-3xl font-extrabold mt-2 tracking-tight">UGX {{ number_format($totalRevenueThisMonth) }}</h2>
                        <p class="text-gray-400 text-xs mt-2">
                            @if($monthlyGrowthPercent >= 0)
                                <span class="text-green-400">↑ {{ number_format(abs($monthlyGrowthPercent), 1) }}%</span>
                            @else
                                <span class="text-red-400">↓ {{ number_format(abs($monthlyGrowthPercent), 1) }}%</span>
                            @endif
                            vs last month
                        </p>
                    </div>
                    <div class="mt-4">
                        <a href="{{ route('platform.revenue.index') }}" class="text-sm font-medium text-blue-400 hover:text-blue-300 flex items-center gap-1 transition-colors">
                            View Revenue Breakdown
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </a>
                    </div>
                </div>

                {{-- Weekly Commissions --}}
                <div class="bg-blue-600 rounded-xl p-5 text-white shadow-sm flex flex-col justify-between">
                    <div class="flex justify-between items-start">
                        <p class="text-sm font-medium text-blue-100">Commissions (This Week)</p>
                        <svg class="w-5 h-5 text-blue-200 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                    </div>
                    <div>
                        <p class="text-2xl font-bold mt-3">UGX {{ number_format($weeklyCommission) }}</p>
                        <p class="text-xs text-blue-200 mt-1">
                            {{ $weeklyGrowthPercent >= 0 ? '+' : '' }}{{ number_format($weeklyGrowthPercent, 1) }}% from last week
                        </p>
                    </div>
                </div>

                {{-- YTD Commissions --}}
                <div class="bg-purple-600 rounded-xl p-5 text-white shadow-sm flex flex-col justify-between">
                    <div class="flex justify-between items-start">
                        <p class="text-sm font-medium text-indigo-100">Commissions (YTD)</p>
                        <svg class="w-5 h-5 text-indigo-200 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    </div>
                    <div>
                        <p class="text-2xl font-bold mt-3">UGX {{ number_format($ytdCommission) }}</p>
                        <p class="text-xs text-indigo-200 mt-1">Jan 1 – Today</p>
                    </div>
                </div>

                {{-- Total Tenants --}}
                <div class="bg-orange-500 rounded-xl p-5 text-white shadow-sm flex flex-col justify-between">
                    <div class="flex justify-between items-start">
                        <p class="text-sm font-medium text-orange-100">Operator Tenants</p>
                        <svg class="w-5 h-5 text-orange-200 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                    </div>
                    <div>
                        <p class="text-3xl font-bold mt-2">{{ $totalTenants }}</p>
                        <div class="flex gap-2 text-[11px] mt-2 font-semibold text-orange-100 flex-wrap">
                            <span>{{ $activeTenants }} Active</span>
                            <span class="text-orange-300">•</span>
                            <span>{{ $trialTenants }} Trial</span>
                            <span class="text-orange-300">•</span>
                            <span>{{ $suspendedTenants }} Suspended</span>
                        </div>
                    </div>
                </div>

            </div>

            {{-- ── Row 2: Operational KPIs ──────────────────────────────── --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">

                {{-- Monthly Commissions --}}
                <div class="bg-orange-500 p-5 rounded-xl shadow-sm border border-gray-200 flex flex-col justify-between">
                    <div class="flex justify-between items-start">
                        <p class="text-xs font-semibold text-white uppercase tracking-wide">Voucher Commissions ({{ now()->format('M') }})</p>
                        <div class="p-1.5 bg-orange-50 rounded-lg shrink-0">
                            <svg class="w-4 h-4 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                    </div>
                    <div>
                        <p class="text-xl font-bold text-gray-900 mt-3">UGX {{ number_format($monthlyCommission) }}</p>
                        <p class="text-xs text-white mt-1">5% from all voucher sales</p>
                    </div>
                </div>

                {{-- Total Payouts Released --}}
                <div class="bg-green-600 p-5 rounded-xl shadow-sm border border-gray-200 flex flex-col justify-between">
                    <div class="flex justify-between items-start">
                        <p class="text-xs font-semibold text-white uppercase tracking-wide">Payouts Released</p>
                        <div class="p-1.5 bg-blue-50 rounded-lg shrink-0">
                            <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                        </div>
                    </div>
                    <div>
                        <p class="text-xl font-bold text-gray-900 mt-3">UGX {{ number_format($totalPayouts) }}</p>
                        <p class="text-xs text-white mt-1">Paid to operators (approved)</p>
                    </div>
                </div>

                {{-- Pending Payouts --}}
                <div class="bg-red-500 p-5 rounded-xl shadow-sm border border-gray-200 border-l-4 border-l-yellow-400 flex flex-col justify-between">
                    <div class="flex justify-between items-start">
                        <p class="text-xs font-semibold text-white uppercase tracking-wide">Pending Payouts</p>
                        <div class="p-1.5 bg-yellow-50 rounded-lg shrink-0">
                            <svg class="w-4 h-4 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                    </div>
                    <div>
                        <p class="text-xl font-bold text-gray-900 mt-3">UGX {{ number_format($pendingPayouts) }}</p>
                        @if($pendingPayoutsCount > 0)
                            <a href="{{ route('platform.revenue.index') }}" class="text-xs text-yellow-600 font-bold hover:underline mt-1 block">
                                {{ $pendingPayoutsCount }} {{ Str::plural('request', $pendingPayoutsCount) }} need approval →
                            </a>
                        @else
                            <p class="text-xs text-yellow-600 font-bold mt-1">No pending requests</p>
                        @endif
                    </div>
                </div>

                {{-- Total End Customers --}}
                <div class="bg-white p-5 rounded-xl shadow-sm border border-gray-200 flex flex-col justify-between">
                    <div class="flex justify-between items-start">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Total End-Customers</p>
                        <div class="p-1.5 bg-green-50 rounded-lg shrink-0">
                            <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        </div>
                    </div>
                    <div>
                        <p class="text-xl font-bold text-gray-900 mt-3">{{ number_format($totalCustomers) }}</p>
                        <p class="text-xs text-gray-400 mt-1">Across all operator networks</p>
                    </div>
                </div>

            </div>

            {{-- ── Middle: Chart + Alerts ───────────────────────────────── --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                {{-- Revenue Chart --}}
                <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <div class="flex justify-between items-center mb-6">
                        <div>
                            <h3 class="text-base font-bold text-gray-900">Revenue & Growth Trends</h3>
                            <p class="text-xs text-gray-500 mt-0.5">Commissions vs Subscriptions — last 6 months</p>
                        </div>
                    </div>
                    <div class="h-64">
                        <canvas id="growthChart"></canvas>
                    </div>
                </div>

                {{-- System Alerts --}}
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h3 class="text-base font-bold text-gray-900 mb-4">System Alerts</h3>
                    <div class="space-y-3">

                        {{-- Pending Payouts Alert --}}
                        @if($pendingPayoutsCount > 0)
                        <div class="flex items-start gap-3 p-3 bg-red-50 rounded-lg border border-red-100">
                            <div class="p-1.5 bg-red-100 rounded-full text-red-600 mt-0.5 shrink-0">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-gray-900">{{ $pendingPayoutsCount }} {{ Str::plural('Withdrawal', $pendingPayoutsCount) }} Pending</p>
                                <a href="{{ route('platform.revenue.index') }}" class="text-xs text-red-600 font-medium hover:underline mt-1 block">Review &amp; Approve →</a>
                            </div>
                        </div>
                        @else
                        <div class="flex items-start gap-3 p-3 bg-green-50 rounded-lg border border-green-100">
                            <div class="p-1.5 bg-green-100 rounded-full text-green-600 mt-0.5 shrink-0">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-gray-900">No Pending Withdrawals</p>
                                <p class="text-xs text-gray-500 mt-1">All payout requests are cleared.</p>
                            </div>
                        </div>
                        @endif

                        {{-- Overdue Subscriptions Alert --}}
                        @if($overdueTenants > 0)
                        <div class="flex items-start gap-3 p-3 bg-orange-50 rounded-lg border border-orange-100">
                            <div class="p-1.5 bg-orange-100 rounded-full text-orange-600 mt-0.5 shrink-0">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-gray-900">{{ $overdueTenants }} Overdue {{ Str::plural('Subscription', $overdueTenants) }}</p>
                                <a href="{{ route('platform.billing.index') }}" class="text-xs text-orange-600 font-medium hover:underline mt-1 block">View Billing →</a>
                            </div>
                        </div>
                        @endif

                        {{-- Trial Tenants --}}
                        @if($trialTenants > 0)
                        <div class="flex items-start gap-3 p-3 bg-blue-50 rounded-lg border border-blue-100">
                            <div class="p-1.5 bg-blue-100 rounded-full text-blue-600 mt-0.5 shrink-0">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-gray-900">{{ $trialTenants }} Tenants on Trial</p>
                                <a href="{{ route('platform.tenants.index') }}" class="text-xs text-blue-600 font-medium hover:underline mt-1 block">Convert to Paid →</a>
                            </div>
                        </div>
                        @endif

                        @php
                            $systemHealth = app(\App\Services\SystemHealthService::class)->current();
                        @endphp
                        <div class="flex items-start gap-3 p-3 rounded-lg border {{ $systemHealth['overall'] === 'healthy' ? 'bg-green-50 border-green-100' : 'bg-amber-50 border-amber-200' }}">
                            <div class="p-1.5 rounded-full mt-0.5 shrink-0 {{ $systemHealth['overall'] === 'healthy' ? 'bg-green-100 text-green-600' : 'bg-amber-100 text-amber-700' }}">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-gray-900">
                                    {{ $systemHealth['overall'] === 'healthy' ? 'System dependencies healthy' : ($systemHealth['overall'] === 'unknown' ? 'System health unknown' : 'System health degraded') }}
                                </p>
                                <div class="mt-1 space-y-0.5">
                                    @foreach($systemHealth['checks'] as $name => $check)
                                        <p class="text-xs {{ $check['status'] === 'healthy' ? 'text-gray-500' : 'text-amber-800' }}">
                                            {{ ucfirst($name) }}: {{ $check['message'] }}
                                        </p>
                                    @endforeach
                                    <p class="text-[11px] text-gray-400">
                                        {{ $systemHealth['checked_at'] ? 'Last check '.$systemHealth['checked_at'] : 'Waiting for first scheduled health check' }}
                                    </p>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            {{-- ── Recent Operator Signups ───────────────────────────────── --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
                    <div>
                        <h3 class="text-sm font-bold text-gray-900">Recent Operator Signups</h3>
                        <p class="text-xs text-gray-500 mt-0.5">Last 5 registered tenants</p>
                    </div>
                    <a href="{{ route('platform.tenants.index') }}" class="text-blue-600 text-xs font-semibold hover:underline">View all →</a>
                </div>

                <table class="min-w-full divide-y divide-gray-100">
                    <thead class="bg-white">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Operator</th>
                            <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Plan</th>
                            <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Date Joined</th>
                            <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wider">Action</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100">
                        @forelse($recentTenants as $tenant)
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center gap-3">
                                    <div class="h-8 w-8 rounded-full bg-blue-100 flex items-center justify-center text-blue-700 font-bold text-xs shrink-0">
                                        {{ strtoupper(substr($tenant->name, 0, 1)) }}
                                    </div>
                                    <div>
                                        <div class="text-sm font-bold text-gray-900">{{ $tenant->name }}</div>
                                        <div class="text-[11px] text-gray-400 font-mono">{{ $tenant->host() }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @if($tenant->plan)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-blue-50 text-blue-700 border border-blue-200">
                                        {{ $tenant->plan->name }}
                                    </span>
                                    <div class="text-[11px] text-gray-400 mt-0.5">UGX {{ number_format($tenant->plan->price) }}/mo</div>
                                @else
                                    <span class="text-xs text-gray-400">No plan</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                {{ $tenant->created_at->diffForHumans() }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @if($tenant->subscription_status === 'active')
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-bold bg-green-100 text-green-800">
                                        <span class="w-1.5 h-1.5 rounded-full bg-green-600"></span> Active
                                    </span>
                                @elseif($tenant->subscription_status === 'trial')
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-bold bg-blue-100 text-blue-800">
                                        <span class="w-1.5 h-1.5 rounded-full bg-blue-600"></span> Trial
                                    </span>
                                @elseif($tenant->subscription_status === 'past_due')
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-bold bg-orange-100 text-orange-800">
                                        <span class="w-1.5 h-1.5 rounded-full bg-orange-500"></span> Overdue
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-bold bg-red-100 text-red-800">
                                        <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span> Suspended
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                <a href="{{ route('platform.tenants.show', $tenant) }}"
                                   class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold text-blue-600 bg-blue-50 hover:bg-blue-100 rounded-lg transition-colors">
                                    View
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                </a>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="px-6 py-10 text-center text-sm text-gray-400">
                                No tenants registered yet.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        </div>
    </div>

    {{-- Chart.js + render script --}}
    @push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js" defer></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const ctx = document.getElementById('growthChart');
            if (!ctx) return;

            const chartData = @json($chartData);
            const labels        = chartData.map(d => d.month);
            const subscriptions = chartData.map(d => d.subscriptions);
            const commissions   = chartData.map(d => d.commissions);

            new Chart(ctx.getContext('2d'), {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        {
                            label: 'Subscription Revenue',
                            data: subscriptions,
                            borderColor: '#3B82F6',
                            backgroundColor: 'rgba(59,130,246,0.08)',
                            tension: 0.4,
                            fill: true,
                            pointBackgroundColor: '#3B82F6',
                            pointRadius: 4,
                        },
                        {
                            label: 'Voucher Commissions',
                            data: commissions,
                            borderColor: '#F97316',
                            backgroundColor: 'transparent',
                            borderDash: [5, 5],
                            tension: 0.4,
                            pointBackgroundColor: '#F97316',
                            pointRadius: 4,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            labels: { boxWidth: 10, font: { size: 11 }, usePointStyle: true },
                        },
                        tooltip: {
                            callbacks: {
                                label: ctx => 'UGX ' + Number(ctx.raw).toLocaleString(),
                            },
                        },
                    },
                    scales: {
                        y: {
                            border: { display: false },
                            grid: { color: '#f3f4f6' },
                            ticks: {
                                callback: value => {
                                    if (value >= 1_000_000) return 'UGX ' + (value / 1_000_000).toFixed(1) + 'M';
                                    if (value >= 1_000)     return 'UGX ' + (value / 1_000).toFixed(0) + 'K';
                                    return 'UGX ' + value;
                                },
                                font: { size: 10 },
                            },
                        },
                        x: {
                            grid: { display: false },
                            ticks: { font: { size: 11 } },
                        },
                    },
                },
            });
        });
    </script>
    @endpush
</x-app-layout>
