<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Processing payment - {{ $tenant->name }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet">
    @vite('resources/css/public.css')
    @vite('resources/js/public.js')
    <style>
        body { font-family: 'Figtree', sans-serif; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4">
    <main class="w-full max-w-sm rounded-3xl border border-gray-100 bg-white p-8 text-center shadow-xl">
        <div class="mx-auto mb-6 flex h-20 w-20 animate-pulse items-center justify-center rounded-full bg-blue-100">
            <svg class="h-10 w-10 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
        </div>

        <h1 class="mb-2 text-2xl font-bold text-gray-900">Waiting for payment</h1>
        <p class="mb-6 text-sm text-gray-500">Approve the mobile money prompt on your phone. Keep this page open while we confirm your payment.</p>

        <div id="status-message" role="status" aria-live="polite" class="text-sm font-medium text-blue-600">
            Checking payment status…
        </div>

        <div class="mt-6 space-y-1 text-xs text-gray-400">
            <p>Reference: {{ $transaction->reference }}</p>
            <p>Amount: UGX {{ number_format((float) $transaction->amount) }}</p>
        </div>

        <a id="return-link" href="{{ $portalUrl }}" class="mt-6 hidden text-sm font-semibold text-blue-600 hover:text-blue-700">
            Return to the portal
        </a>
    </main>

    <x-ui.toast />

    <script>
        const checkUrl = @json($checkUrl);
        const successUrl = @json($successUrl);
        const statusElement = document.getElementById('status-message');
        const returnLink = document.getElementById('return-link');

        async function checkPayment() {
            try {
                const result = await window.apiFetch(checkUrl, {
                    cache: 'no-store',
                });

                if (result.status === 'completed') {
                    statusElement.textContent = 'Payment successful. Redirecting…';
                    window.location.assign(result.redirect_url || successUrl);
                    return;
                }

                if (result.status === 'failed') {
                    statusElement.textContent = result.message || 'Payment failed. Please try again.';
                    statusElement.className = 'text-sm font-medium text-red-600';
                    returnLink.classList.remove('hidden');
                    return;
                }

                if (result.status === 'provisioning' || result.status === 'activation_pending') {
                    statusElement.textContent = 'Payment confirmed. Waiting for the site router to activate your access…';
                }
            } catch (error) {
                statusElement.textContent = 'Unable to check right now. We will try again shortly.';
            }

            window.setTimeout(checkPayment, 5000);
        }

        document.addEventListener('DOMContentLoaded', checkPayment, { once: true });
    </script>
</body>
</html>
