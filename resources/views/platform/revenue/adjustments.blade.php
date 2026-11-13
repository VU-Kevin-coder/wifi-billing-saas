<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Pending Adjustments</h2>
    </x-slot>

    <div class="py-8 bg-gray-50 min-h-screen">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if(session('status'))
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">{{ session('status') }}</div>
            @endif
            @if(session('error'))
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">{{ session('error') }}</div>
            @endif

            <!-- Pending -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900">Awaiting Approval</h3>
                        <p class="text-sm text-gray-500 mt-1">Adjustments require a second admin when policy demands it.</p>
                    </div>
                    <a href="{{ route('platform.revenue.index') }}" class="text-sm text-blue-600 hover:underline">
                        &larr; Back to Revenue
                    </a>
                </div>

                @if($pending->isEmpty())
                    <div class="p-8 text-center text-gray-500 text-sm">No pending adjustments.</div>
                @else
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Tenant</th>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Requested by</th>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Reason</th>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Expires</th>
                                <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            @foreach($pending as $adj)
                                <tr>
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $adj->tenant->name }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $adj->requester->name }}</td>
                                    <td class="px-6 py-4 text-sm font-bold {{ $adj->amount < 0 ? 'text-red-600' : 'text-green-600' }}">
                                        {{ $adj->amount < 0 ? '-' : '+' }} UGX {{ number_format(abs($adj->amount)) }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $adj->reason }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-500">{{ $adj->expires_at?->format('d M, H:i') }}</td>
                                    <td class="px-6 py-4 text-right">
                                        <form method="POST" action="{{ route('platform.revenue.adjustments.approve', $adj->id) }}" class="inline"
                                              data-confirm-action="Approve this {{ $adj->amount < 0 ? 'debit' : 'credit' }} adjustment of UGX {{ number_format(abs($adj->amount)) }} for {{ $adj->tenant->name }}?">
                                            @csrf
                                            <button class="text-xs bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded">Approve</button>
                                        </form>
                                        <form method="POST" action="{{ route('platform.revenue.adjustments.reject', $adj->id) }}" class="inline ml-1"
                                              data-confirm-action="Reject this wallet adjustment request for {{ $adj->tenant->name }}?">
                                            @csrf
                                            <input type="hidden" name="note" value="Rejected by {{ auth()->user()->name }}">
                                            <button class="text-xs bg-red-600 hover:bg-red-700 text-white px-3 py-1.5 rounded">Reject</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <!-- Recent -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-bold text-gray-900">Recent Decisions</h3>
                </div>
                @if($recent->isEmpty())
                    <div class="p-8 text-center text-gray-500 text-sm">No recent decisions.</div>
                @else
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Tenant</th>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Decided by</th>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Date</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            @foreach($recent as $adj)
                                <tr>
                                    <td class="px-6 py-4 text-sm text-gray-900">{{ $adj->tenant->name }}</td>
                                    <td class="px-6 py-4 text-sm font-medium">{{ number_format(abs($adj->amount)) }}</td>
                                    <td class="px-6 py-4 text-sm">
                                        <span class="px-2 py-1 rounded text-xs font-bold
                                            {{ $adj->status === 'approved' ? 'bg-green-100 text-green-800' : '' }}
                                            {{ $adj->status === 'rejected' ? 'bg-red-100 text-red-800' : '' }}
                                            {{ $adj->status === 'expired' ? 'bg-gray-100 text-gray-700' : '' }}">
                                            {{ ucfirst($adj->status) }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $adj->approver?->name ?? '—' }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-500">{{ $adj->decided_at?->format('d M H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>

