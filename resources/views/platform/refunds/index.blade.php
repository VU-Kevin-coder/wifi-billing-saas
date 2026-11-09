<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Refund requests</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Refund requests</h1>
                <p class="mt-1 text-sm text-gray-500">
                    Refund the customer through your payment provider first, then enter the payout reference to approve.
                    Approving debits the operator's wallet (their share) and reverses your commission on the refunded amount.
                </p>
            </div>

            @if (session('status'))
                <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-green-700" role="status">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-red-700" role="alert">
                    @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
                </div>
            @endif

            <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 p-4"><h2 class="font-bold text-gray-900">Waiting for a decision ({{ $pending->count() }})</h2></div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                            <tr><th class="px-4 py-3">Operator</th><th class="px-4 py-3">Sale</th><th class="px-4 py-3">Refund</th><th class="px-4 py-3">Reason</th><th class="px-4 py-3">Decision</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($pending as $refund)
                                <tr>
                                    <td class="px-4 py-3">{{ $refund->tenant?->name }}<br><span class="text-xs text-gray-500">by {{ $refund->requester?->name ?? 'unknown' }}</span></td>
                                    <td class="px-4 py-3 font-mono text-xs">{{ $refund->transaction?->reference }}<br><span class="text-gray-500">{{ $refund->transaction?->phone }}</span></td>
                                    <td class="px-4 py-3">{{ $currency }} {{ number_format($refund->amount, 0) }}<br><span class="text-xs text-gray-500">of {{ number_format($refund->transaction?->amount ?? 0, 0) }}{{ $refund->revoke_access ? ', remove access' : '' }}</span></td>
                                    <td class="px-4 py-3">{{ $refund->reason }}</td>
                                    <td class="px-4 py-3">
                                        <form method="POST" action="{{ route('platform.refunds.approve', $refund->id) }}" class="mb-2 flex items-center gap-2">
                                            @csrf
                                            <label class="sr-only" for="payout-{{ $refund->id }}">Payout reference</label>
                                            <input id="payout-{{ $refund->id }}" name="payout_reference" type="text" required minlength="3" maxlength="100" placeholder="Provider payout reference" class="w-52 rounded-lg border border-gray-300 px-2 py-1 text-sm">
                                            <button type="submit" class="rounded-lg bg-green-600 px-3 py-1 text-xs font-medium text-white hover:bg-green-700">Approve</button>
                                        </form>
                                        <form method="POST" action="{{ route('platform.refunds.reject', $refund->id) }}" class="flex items-center gap-2">
                                            @csrf
                                            <label class="sr-only" for="note-{{ $refund->id }}">Note</label>
                                            <input id="note-{{ $refund->id }}" name="note" type="text" maxlength="500" placeholder="Reason for rejecting (optional)" class="w-52 rounded-lg border border-gray-300 px-2 py-1 text-sm">
                                            <button type="submit" class="rounded-lg bg-red-600 px-3 py-1 text-xs font-medium text-white hover:bg-red-700">Reject</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">Nothing is waiting.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 p-4"><h2 class="font-bold text-gray-900">Recently decided</h2></div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                            <tr><th class="px-4 py-3">Decided</th><th class="px-4 py-3">Operator</th><th class="px-4 py-3">Amount</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Payout reference</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($recent as $refund)
                                <tr>
                                    <td class="px-4 py-3">{{ $refund->decided_at?->format('d M Y H:i') }}<br><span class="text-xs text-gray-500">{{ $refund->decider?->name }}</span></td>
                                    <td class="px-4 py-3">{{ $refund->tenant?->name }}</td>
                                    <td class="px-4 py-3">{{ $currency }} {{ number_format($refund->amount, 0) }}</td>
                                    <td class="px-4 py-3 capitalize">{{ $refund->status }}</td>
                                    <td class="px-4 py-3 font-mono text-xs">{{ $refund->payout_reference }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">No decisions yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
