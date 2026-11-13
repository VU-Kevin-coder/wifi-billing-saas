<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Refunds</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Refund requests</h1>
                <p class="mt-1 text-sm text-gray-500">
                    Ask the platform to refund a customer for a completed online sale. When approved, your share of the
                    refund is taken from your wallet and, for a full refund, the customer's access is removed.
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
                <div class="border-b border-gray-100 p-4"><h2 class="font-bold text-gray-900">Recent sales you can refund</h2></div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                            <tr><th class="px-4 py-3">Sale</th><th class="px-4 py-3">Date</th><th class="px-4 py-3">Paid</th><th class="px-4 py-3">Refundable</th><th class="px-4 py-3">Request a refund</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($sales as $sale)
                                <tr>
                                    <td class="px-4 py-3 font-mono text-xs">{{ $sale->reference }}</td>
                                    <td class="px-4 py-3">{{ $sale->created_at->format('d M Y H:i') }}</td>
                                    <td class="px-4 py-3">{{ $currency }} {{ number_format($sale->amount, 0) }}</td>
                                    <td class="px-4 py-3">{{ $currency }} {{ number_format($sale->refundable, 0) }}</td>
                                    <td class="px-4 py-3">
                                        <form method="POST" action="{{ route('operator.refunds.store', ['tenant' => $tenant->slug]) }}" class="flex flex-wrap items-center gap-2"
                                              data-confirm-action="Submit this refund request? It will be reviewed by the platform before any refund is issued.">
                                            @csrf
                                            <input type="hidden" name="transaction_id" value="{{ $sale->id }}">
                                            <label class="sr-only" for="amount-{{ $sale->id }}">Amount</label>
                                            <input id="amount-{{ $sale->id }}" name="amount" type="number" step="0.01" min="0.01" max="{{ $sale->refundable }}" value="{{ $sale->refundable }}" required class="w-28 rounded-lg border border-gray-300 px-2 py-1 text-sm">
                                            <label class="sr-only" for="reason-{{ $sale->id }}">Reason</label>
                                            <input id="reason-{{ $sale->id }}" name="reason" type="text" minlength="10" maxlength="500" required placeholder="Reason (min 10 characters)" class="w-64 rounded-lg border border-gray-300 px-2 py-1 text-sm">
                                            <label class="flex items-center gap-1 text-xs text-gray-600"><input type="checkbox" name="revoke_access" value="1" checked> Remove access</label>
                                            <button type="submit" class="rounded-lg bg-blue-600 px-3 py-1 text-xs font-medium text-white hover:bg-blue-700">Request</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">No refundable sales.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 p-4"><h2 class="font-bold text-gray-900">Your requests</h2></div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                            <tr><th class="px-4 py-3">Requested</th><th class="px-4 py-3">Amount</th><th class="px-4 py-3">Reason</th><th class="px-4 py-3">Status</th><th class="px-4 py-3"></th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($requests as $refund)
                                <tr>
                                    <td class="px-4 py-3">{{ $refund->created_at->format('d M Y H:i') }}</td>
                                    <td class="px-4 py-3">{{ $currency }} {{ number_format($refund->amount, 0) }}</td>
                                    <td class="px-4 py-3">{{ $refund->reason }}</td>
                                    <td class="px-4 py-3 capitalize">{{ $refund->status }}@if($refund->decision_note) <span class="text-xs text-gray-500">({{ $refund->decision_note }})</span>@endif</td>
                                    <td class="px-4 py-3">
                                        @if ($refund->status === 'pending')
                                            <form method="POST" action="{{ route('operator.refunds.cancel', ['tenant' => $tenant->slug, 'refund' => $refund->id]) }}"
                                                  data-confirm-action="Cancel this pending refund request?">
                                                @csrf
                                                <button type="submit" class="text-xs font-medium text-red-600 hover:underline">Cancel</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">No refund requests yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($requests->hasPages())<div class="border-t border-gray-100 p-4">{{ $requests->links() }}</div>@endif
            </section>
        </div>
    </div>
</x-app-layout>
