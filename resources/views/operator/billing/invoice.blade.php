<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Invoice #{{ $record->id }}</h2>
    </x-slot>

    @php
        $invoiceStatus = $record->status === 'review' ? 'Paid - administrator review required' : ucfirst($record->status);
        $paymentStatus = ucfirst($attempt->status ?? 'not recorded');
        $providerLabels = ['obpay' => 'OBPay', 'pawapay' => 'PawaPay', 'iotec' => 'ioTec'];
    @endphp

    <div class="py-8">
        <main class="mx-auto max-w-3xl space-y-5 px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between gap-3 print:hidden">
                <a href="{{ route('operator.billing.index') }}" class="text-sm font-semibold text-blue-700 underline">Back to billing</a>
                <button type="button" onclick="window.print()" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700">Print / Save PDF</button>
            </div>

            <article class="rounded-xl border border-gray-200 bg-white p-8 shadow-sm print:border-0 print:p-0 print:shadow-none">
                <header class="flex flex-col gap-5 border-b border-gray-200 pb-6 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-wide text-blue-700">Subscription invoice</p>
                        <h1 class="mt-2 text-3xl font-bold text-gray-900">Invoice #{{ $record->id }}</h1>
                        <p class="mt-2 text-sm text-gray-500">Issued {{ \Illuminate\Support\Carbon::parse($record->created_at)->format('d M Y') }}</p>
                    </div>
                    <dl class="text-sm sm:text-right">
                        <dt class="text-gray-500">Invoice status</dt>
                        <dd class="font-semibold text-gray-900">{{ $invoiceStatus }}</dd>
                        @if($attempt)
                            <dt class="mt-3 text-gray-500">Payment status</dt>
                            <dd class="font-semibold text-gray-900">{{ $paymentStatus }}</dd>
                        @endif
                    </dl>
                </header>

                <section class="grid gap-6 py-6 sm:grid-cols-2">
                    <div>
                        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Billed to</h2>
                        <p class="mt-2 font-semibold text-gray-900">{{ $tenant->name }}</p>
                        <p class="text-sm text-gray-500">{{ $tenant->slug }}</p>
                    </div>
                    <div class="sm:text-right">
                        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Payment reference</h2>
                        <p class="mt-2 break-all font-mono text-sm text-gray-900">{{ $attempt->reference ?? 'Not recorded' }}</p>
                        @if($attempt && $attempt->gateway_provider)
                            <p class="mt-1 text-sm text-gray-500">{{ $providerLabels[$attempt->gateway_provider] ?? 'Payment provider' }}</p>
                        @endif
                    </div>
                </section>

                <div class="overflow-x-auto border-y border-gray-200 py-4">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="text-xs uppercase tracking-wide text-gray-500">
                                <th class="py-2">Description</th>
                                <th class="py-2 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="py-4">
                                    <p class="font-semibold text-gray-900">Monthly renewal of {{ $record->plan_name }}</p>
                                    <p class="text-gray-500">One month of subscription service</p>
                                </td>
                                <td class="py-4 text-right font-semibold text-gray-900">{{ $record->currency }} {{ number_format((int) $record->amount) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-gray-200">
                                <th class="pt-4 text-right font-semibold text-gray-900">Total</th>
                                <td class="pt-4 text-right text-lg font-bold text-gray-900">{{ $record->currency }} {{ number_format((int) $record->amount) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                @if($record->paid_at)
                    <p class="mt-5 text-sm text-gray-600">Payment recorded {{ \Illuminate\Support\Carbon::parse($record->paid_at)->format('d M Y H:i') }} UTC.</p>
                @endif

                @if($checkoutUrl)
                    <a href="{{ $checkoutUrl }}" rel="noopener" class="mt-6 inline-flex rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white print:hidden">Continue hosted payment</a>
                @endif

                @if($verificationAvailable && $record->status === 'open' && $attempt && in_array($attempt->status, ['unknown', 'pending', 'failed'], true))
                    <form method="POST" action="{{ route('operator.billing.check', ['attempt' => $attempt->id]) }}" class="mt-6 print:hidden">
                        @csrf
                        <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white">Check payment status</button>
                        <p class="mt-2 text-xs text-gray-500">Payment is not confirmed until the provider status is verified.</p>
                    </form>
                @endif
            </article>
        </main>
    </div>

    <style>
        @media print {
            body { background: white !important; }
        }
    </style>
</x-app-layout>
