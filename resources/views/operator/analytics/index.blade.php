<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Analytics</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Sales analytics</h1>
                    <p class="mt-1 text-sm text-gray-500">Revenue is counted when a voucher is redeemed. Times are in your local timezone.</p>
                </div>
                <nav class="flex gap-1 rounded-lg border border-gray-200 bg-white p-1 text-sm" aria-label="Period">
                    @foreach ($periods as $period)
                        <a href="{{ request()->url() }}?days={{ $period }}"
                           class="rounded-md px-3 py-1 {{ $days === $period ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100' }}"
                           @if($days === $period) aria-current="page" @endif>{{ $period }} days</a>
                    @endforeach
                </nav>
            </div>

            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm"><p class="text-xs uppercase text-gray-500">Revenue</p><p class="mt-1 text-2xl font-bold">{{ $currency }} {{ number_format($revenue, 0) }}</p></div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm"><p class="text-xs uppercase text-gray-500">Vouchers redeemed</p><p class="mt-1 text-2xl font-bold">{{ number_format($count) }}</p></div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm"><p class="text-xs uppercase text-gray-500">Customers (with a phone)</p><p class="mt-1 text-2xl font-bold">{{ number_format($customers) }}</p></div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm"><p class="text-xs uppercase text-gray-500">Came back (2+ purchases)</p><p class="mt-1 text-2xl font-bold">{{ $repeatRate }}%</p><p class="text-xs text-gray-500">{{ number_format($repeatBuyers) }} of {{ number_format($customers) }}</p></div>
            </div>

            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="font-bold text-gray-900">Revenue per day</h2>
                <div class="mt-4 flex h-40 items-end gap-1 overflow-x-auto" role="img" aria-label="Daily revenue bars">
                    @foreach ($daily as $day)
                        <div class="flex min-w-[10px] flex-1 h-full flex-col items-center justify-end" title="{{ $day['label'] }}: {{ $currency }} {{ number_format($day['revenue'], 0) }} ({{ $day['count'] }} vouchers)">
                            <div class="w-full rounded-t bg-blue-500" style="height: {{ max(1, round($day['revenue'] / $maxDaily * 100)) }}%"></div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-1 flex justify-between text-xs text-gray-500"><span>{{ $daily[0]['label'] }}</span><span>{{ end($daily)['label'] }}</span></div>
            </section>

            <div class="grid gap-8 lg:grid-cols-2">
                <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <h2 class="font-bold text-gray-900">Busiest hours</h2>
                    <p class="text-xs text-gray-500">When customers redeem vouchers (local time).</p>
                    <ul class="mt-3 space-y-1">
                        @foreach ($hours as $hour => $n)
                            <li class="flex items-center gap-2 text-xs">
                                <span class="w-10 text-gray-500">{{ str_pad($hour, 2, '0', STR_PAD_LEFT) }}:00</span>
                                <span class="h-3 rounded bg-blue-400" style="width: {{ $n > 0 ? max(2, round($n / $maxHour * 100)) : 0 }}%"></span>
                                <span class="text-gray-600">{{ $n }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>

                <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <h2 class="font-bold text-gray-900">Packages</h2>
                    <table class="mt-3 min-w-full text-sm">
                        <thead class="text-left text-xs uppercase text-gray-500"><tr><th class="py-2">Package</th><th class="py-2">Sold</th><th class="py-2">Revenue</th></tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($packages as $name => $row)
                                <tr><td class="py-2">{{ $name }}</td><td class="py-2">{{ $row['count'] }}</td><td class="py-2">{{ $currency }} {{ number_format($row['revenue'], 0) }}</td></tr>
                            @empty
                                <tr><td colspan="3" class="py-6 text-center text-gray-500">No sales in this period.</td></tr>
                            @endforelse
                        </tbody>
                    </table>

                    <h2 class="mt-8 font-bold text-gray-900">SMS expiry reminders</h2>
                    @if ($reminders['sent'] > 0)
                        <p class="mt-2 text-sm text-gray-700">
                            {{ number_format($reminders['sent']) }} sent; <strong>{{ number_format($reminders['returned']) }}</strong>
                            ({{ $reminders['rate'] }}%) of those customers bought again within a day.
                        </p>
                    @else
                        <p class="mt-2 text-sm text-gray-500">No reminders sent in this period. Turn them on in Settings &gt; SMS.</p>
                    @endif
                </section>
            </div>
        </div>
    </div>
</x-app-layout>
