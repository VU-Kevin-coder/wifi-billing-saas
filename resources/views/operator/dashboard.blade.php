<x-app-layout>
    <div class="min-h-full bg-gray-50 px-4 py-6 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl space-y-5">
            <section class="flex flex-col gap-5 rounded-xl border border-gray-200 bg-white p-5 shadow-sm lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h1 class="text-xl font-bold tracking-tight text-gray-900 sm:text-2xl">
                        Good {{ now()->format('H') < 12 ? 'morning' : (now()->format('H') < 18 ? 'afternoon' : 'evening') }}, {{ explode(' ', auth()->user()->name)[0] }}
                    </h1>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <form method="GET" action="{{ route('operator.dashboard', ['tenant' => $tenant->slug]) }}">
                            <label for="dashboard-currency" class="sr-only">Revenue currency</label>
                            <select id="dashboard-currency" name="currency" onchange="this.form.submit()" class="rounded-full border-gray-200 bg-gray-50 py-1 pl-3 pr-8 text-xs font-medium text-gray-700 focus:border-blue-500 focus:ring-blue-500">
                                @foreach ($currencies as $availableCurrency)
                                    <option value="{{ $availableCurrency }}" @selected($currency === $availableCurrency)>{{ $availableCurrency }}</option>
                                @endforeach
                            </select>
                        </form>
                        <span class="text-sm text-gray-500">Here is what is happening with {{ $tenant->name }} today.</span>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center gap-2 rounded-full bg-gray-100 px-3 py-2 text-xs font-semibold text-gray-700">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Subscription usage not configured
                    </span>
                    <a href="{{ route('operator.settings.index', ['tenant' => $tenant->slug, 'tab' => 'sms']) }}" class="inline-flex items-center gap-2 rounded-lg bg-orange-500 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-orange-600">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                        Configure SMS
                    </a>
                </div>
            </section>

            <section class="grid grid-cols-1 gap-5 lg:grid-cols-3">
                <x-ui.card class="p-5 lg:col-span-2">
                    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-sm font-bold text-gray-900">Revenue trends</h2>
                            <p class="mt-1 text-xs text-gray-500">Daily revenue and voucher redemptions · last 7 days</p>
                        </div>
                        <div class="flex items-center gap-4 text-xs text-gray-600">
                            <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-blue-500"></span>Revenue</span>
                            <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-emerald-500"></span>Transactions</span>
                        </div>
                    </div>
                    <div class="relative h-56 sm:h-64">
                        <canvas id="revenueChart" role="img" aria-label="Revenue and voucher redemptions over the last seven days"></canvas>
                        <p data-chart-fallback class="hidden mt-4 text-center text-xs text-amber-700">The chart library could not be loaded. Dashboard totals are still available.</p>
                    </div>
                </x-ui.card>

                <x-ui.card class="p-5">
                    <h2 class="text-sm font-bold text-gray-900">User status</h2>
                    <p class="mt-1 text-xs text-gray-500">Online sessions and registered customers</p>
                    <div class="relative mx-auto mt-3 h-40 max-w-48">
                        <canvas id="userStatusChart" role="img" aria-label="Active sessions compared with offline customers"></canvas>
                        <p data-chart-fallback class="hidden mt-4 text-center text-xs text-amber-700">The chart library could not be loaded.</p>
                        <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                            <span class="text-2xl font-bold text-gray-900">{{ $onlinePercentage }}%</span>
                            <span class="text-xs text-gray-500">online</span>
                        </div>
                    </div>
                    <div class="mt-3 space-y-2 border-t border-gray-100 pt-3 text-xs">
                        <div class="flex items-center justify-between"><span class="inline-flex items-center gap-2 text-gray-600"><span class="h-2 w-2 rounded-full bg-blue-500"></span>Online</span><strong class="text-gray-900">{{ number_format($activeSessions) }}</strong></div>
                        <div class="flex items-center justify-between"><span class="inline-flex items-center gap-2 text-gray-600"><span class="h-2 w-2 rounded-full bg-orange-500"></span>Offline</span><strong class="text-gray-900">{{ number_format($offlineCustomers) }}</strong></div>
                        <div class="flex items-center justify-between"><span class="inline-flex items-center gap-2 text-gray-600"><span class="h-2 w-2 rounded-full bg-purple-500"></span>All users</span><strong class="text-gray-900">{{ number_format($totalUsers) }}</strong></div>
                    </div>
                </x-ui.card>
            </section>

            <section class="grid grid-cols-2 gap-3 md:grid-cols-4" aria-label="Business metrics">
                <x-ui.stat-card label="Today's revenue" :value="$currency . ' ' . number_format($todayRevenue)" description="Redeemed vouchers today" tone="blue" class="p-4 sm:p-5" />
                <x-ui.stat-card label="Weekly revenue" :value="$currency . ' ' . number_format($weeklyRevenue)" description="This week" tone="amber" class="p-4 sm:p-5" />
                <x-ui.stat-card label="Monthly revenue" :value="$currency . ' ' . number_format($monthlyRevenue)" description="This month" tone="purple" class="p-4 sm:p-5" />
                <x-ui.stat-card label="Active users" :value="number_format($activeSessions)" :description="number_format($activeSessions) . ' online · ' . number_format($offlineCustomers) . ' offline'" tone="green" class="p-4 sm:p-5" />
                <x-ui.stat-card label="Wallet balance" :value="$currency . ' ' . number_format((float) ($tenant->wallet_balance ?? 0))" tone="amber" class="p-4 sm:p-5">
                    <a href="{{ route('operator.wallet.index') }}" class="text-[11px] text-white/80 underline hover:text-white">Manage &amp; Withdraw &rarr;</a>
                </x-ui.stat-card>
                <x-ui.stat-card label="Total customers" :value="number_format($totalCustomers)" description="Registered customers" tone="blue" class="p-4 sm:p-5" />
                <x-ui.stat-card label="Router health" :value="number_format($onlineRouters) . ' / ' . number_format($totalRouters)" :description="$totalRouters === 0 ? 'No routers connected' : 'Routers online'" tone="purple" class="p-4 sm:p-5" />
                <x-ui.stat-card label="Data usage" :value="number_format($dataUsageGb, 2) . ' GB'" description="Across active sessions" tone="red" class="p-4 sm:p-5" />
            </section>

            <x-ui.data-table id="recent-packages">
                <x-slot:header>
                    <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-bold text-gray-900">Recent packages</h2>
                        <p class="mt-1 text-xs text-gray-500">Latest packages configured for this workspace</p>
                    </div>
                    <a href="{{ route('operator.packages.index') }}" class="text-xs font-semibold text-blue-600 hover:text-blue-800">View all</a>
                    </div>
                </x-slot:header>
                <table class="min-w-full divide-y divide-gray-100 text-left text-xs">
                        <thead class="bg-gray-50 text-[10px] font-semibold uppercase tracking-wide text-gray-500">
                            <tr>
                                <th scope="col" class="px-5 py-3">Name</th>
                                <th scope="col" class="px-5 py-3">Price</th>
                                <th scope="col" class="px-5 py-3">Resell price</th>
                                <th scope="col" class="px-5 py-3">Shared</th>
                                <th scope="col" class="px-5 py-3">Limits</th>
                                <th scope="col" class="px-5 py-3">Loyalty points</th>
                                <th scope="col" class="px-5 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-gray-700">
                            @forelse ($recentPackages as $package)
                                <tr class="hover:bg-gray-50">
                                    <td class="whitespace-nowrap px-5 py-3.5 font-semibold text-gray-900">{{ $package->name }}</td>
                                    <td class="whitespace-nowrap px-5 py-3.5">{{ $package->currency }} {{ number_format((float) $package->price) }}</td>
                                    <td class="whitespace-nowrap px-5 py-3.5 text-orange-600">Not set</td>
                                    <td class="whitespace-nowrap px-5 py-3.5">{{ $package->device_limit }} {{ \Illuminate\Support\Str::plural('device', $package->device_limit) }}</td>
                                    <td class="whitespace-nowrap px-5 py-3.5">{{ $package->duration_value }} {{ $package->duration_unit }}</td>
                                    <td class="whitespace-nowrap px-5 py-3.5 text-gray-400">Not configured</td>
                                    <td class="whitespace-nowrap px-5 py-3.5">
                                        @if ($package->is_published)
                                            <x-ui.badge tone="green"><span aria-hidden="true">✓</span> Active</x-ui.badge>
                                        @else
                                            <x-ui.badge>Draft</x-ui.badge>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7"><x-ui.empty-state title="No packages yet" message="Create a package to start offering Wi-Fi access."><a href="{{ route('operator.packages.index') }}" class="inline-flex items-center rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700">Create a package</a></x-ui.empty-state></td></tr>
                            @endforelse
                        </tbody>
                </table>
            </x-ui.data-table>
        </div>
    </div>

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js" defer></script>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                if (!window.Chart) {
                    document.querySelectorAll('[data-chart-fallback]').forEach((message) => {
                        message.classList.remove('hidden');
                    });
                    return;
                }

                const chartData = @json($chartData->values());
                const revenueCanvas = document.getElementById('revenueChart');
                const statusCanvas = document.getElementById('userStatusChart');

                if (revenueCanvas) {
                    new Chart(revenueCanvas, {
                        type: 'line',
                        data: {
                            labels: chartData.map((day) => day.label),
                            datasets: [
                                {
                                    label: @json('Revenue ('.$currency.')'),
                                    data: chartData.map((day) => day.revenue),
                                    borderColor: '#2563eb',
                                    backgroundColor: 'rgba(37, 99, 235, 0.12)',
                                    pointRadius: 2,
                                    pointHoverRadius: 4,
                                    tension: 0.35,
                                    fill: true,
                                    yAxisID: 'revenue',
                                },
                                {
                                    label: 'Transactions',
                                    data: chartData.map((day) => day.transactions),
                                    borderColor: '#10b981',
                                    backgroundColor: 'transparent',
                                    pointRadius: 2,
                                    tension: 0.35,
                                    borderDash: [4, 4],
                                    yAxisID: 'transactions',
                                },
                            ],
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: { mode: 'index', intersect: false },
                            plugins: { legend: { display: false } },
                            scales: {
                                revenue: {
                                    type: 'linear',
                                    position: 'left',
                                    beginAtZero: true,
                                    grid: { color: '#f3f4f6', borderDash: [3, 4] },
                                    ticks: { precision: 0 },
                                },
                                transactions: {
                                    type: 'linear',
                                    position: 'right',
                                    beginAtZero: true,
                                    grid: { drawOnChartArea: false },
                                    ticks: { precision: 0 },
                                },
                                x: { grid: { display: false } },
                            },
                        },
                    });
                }

                if (statusCanvas) {
                    new Chart(statusCanvas, {
                        type: 'doughnut',
                        data: {
                            labels: ['Online', 'Offline'],
                            datasets: [{
                                data: [{{ $activeSessions }}, {{ $offlineCustomers }}],
                                backgroundColor: ['#2563eb', '#f97316'],
                                borderWidth: 0,
                                hoverOffset: 3,
                            }],
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            cutout: '74%',
                            plugins: { legend: { display: false } },
                        },
                    });
                }
            });
        </script>
    @endpush
</x-app-layout>
