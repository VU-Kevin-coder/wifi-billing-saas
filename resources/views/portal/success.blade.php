<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connected · {{ $tenant->settings['portal_title'] ?: $tenant->name }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />
    @include('partials.sweetalert-assets')
    @vite('resources/css/public.css')
    @vite('resources/js/public.js')
    <style>
        :root {
            --primary: {{ $tenant->settings['primary_color'] ?? '#3B82F6' }};
            --primary-dark: color-mix(in srgb, var(--primary), black 18%);
            --primary-light: color-mix(in srgb, var(--primary), white 88%);
            --paper: #f2eee6;
            --panel: #fbf9f4;
            --line: #ddd6c8;
        }
        * { box-sizing: border-box; }
        body {
            background: var(--paper);
            font-family: 'Figtree', sans-serif;
        }
        .bg-primary { background-color: var(--primary); }
        .text-primary { color: var(--primary); }
        .portal-card { border-color: var(--line); background: var(--panel); }
        .portal-brand { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; text-align: left; }
        .portal-brand-mark { display: grid; width: 48px; height: 48px; flex: 0 0 48px; place-items: center; overflow: hidden; border: 1px solid var(--line); border-radius: 14px; background: white; color: var(--primary); }
        .portal-brand-mark img { width: 100%; height: 100%; padding: 5px; object-fit: contain; }
        .portal-brand-fallback { width: 28px; height: 28px; }
        .portal-brand strong, .portal-brand span { display: block; }
        .portal-brand strong { font-family: Georgia, "Times New Roman", serif; font-size: 1.12rem; font-weight: 600; }
        .portal-brand span { margin-top: 3px; color: #6f6a60; font-size: .82rem; }
        .voucher-panel { border-color: color-mix(in srgb, var(--primary), white 65%); background: var(--primary-light); }
        .voucher-label, .voucher-note { color: var(--primary-dark); }
        .voucher-code { color: var(--primary); }
    </style>
    <script>
        const paymentToken = @json($paymentToken ?? null);
        const paymentStatusUrl = @json($paymentStatusUrl);
        const paymentActivationUrl = @json($paymentActivationUrl);
        const initialMikrotikUrl = @json($mikrotikUrl);
        const initialVoucherCode = @json($voucherCode ?? null);

        let connectionCountdown;

        function connectToWifi(url, voucherCode = null) {
            if (voucherCode) {
                document.getElementById('voucher-code').textContent = voucherCode;
                document.getElementById('voucher-panel').classList.remove('hidden');
            }

            if (!url) {
                document.getElementById('payment-message').textContent =
                    'Your voucher is ready, but the Wi-Fi login link is unavailable. Use the voucher code to connect.';
                document.getElementById('payment-spinner').classList.add('hidden');
                return;
            }

            window.clearInterval(connectionCountdown);
            document.getElementById('mikrotik-link').href = url;
            document.getElementById('mikrotik-link').classList.remove('hidden');
            document.getElementById('payment-spinner').classList.add('hidden');
            document.getElementById('payment-message').innerHTML =
                'Your access is active. Opening the router login page in <span id="countdown">3</span>s. Enter the voucher code there.';

            let seconds = 3;
            connectionCountdown = window.setInterval(() => {
                seconds--;
                document.getElementById('countdown').textContent = seconds;
                if (seconds <= 0) {
                    window.clearInterval(connectionCountdown);
                    window.location.assign(url);
                }
            }, 1000);
        }

        async function activatePaidVoucher(payment) {
            const activation = await window.apiFetch(paymentActivationUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Payment-Token': paymentToken,
                },
                body: JSON.stringify({}),
                cache: 'no-store',
            });
            if (activation.status !== 'activated') {
                throw new Error(activation.message || 'Voucher activation failed.');
            }

            document.getElementById('page-title').textContent = 'Voucher Activated!';
            connectToWifi(activation.mikrotik_url || payment.mikrotik_url, activation.voucher_code || payment.voucher_code);
        }

        async function checkPaymentStatus() {
            try {
                const payment = await window.apiFetch(paymentStatusUrl, {
                    headers: { 'X-Payment-Token': paymentToken },
                    cache: 'no-store',
                    feedback: false,
                });

                if (payment.status === 'completed') {
                    try {
                        await activatePaidVoucher(payment);
                    } catch (error) {
                        document.getElementById('payment-message').textContent =
                            (error instanceof Error ? error.message : 'Voucher activation failed.') + ' Retry activation below.';
                        document.getElementById('payment-spinner').classList.add('hidden');
                        document.getElementById('activation-retry').classList.remove('hidden');
                    }
                    return;
                }

                if (payment.status === 'failed' || payment.status === 'not_found') {
                    document.getElementById('page-title').textContent = 'Payment not completed';
                    document.getElementById('payment-message').textContent = 'No successful payment was confirmed. Return to the portal and try again if you were not charged.';
                    document.getElementById('payment-spinner').classList.add('hidden');
                    return;
                }

                if (payment.status === 'fulfillment_pending') {
                    document.getElementById('page-title').textContent = 'Payment confirmed';
                    document.getElementById('payment-message').textContent =
                        'Access is being prepared. Please do not pay again.';
                }

                if (payment.status === 'provisioning' || payment.status === 'activation_pending') {
                    document.getElementById('page-title').textContent = 'Activating your Wi-Fi access…';
                    document.getElementById('payment-message').textContent =
                        'Payment confirmed. Waiting for the site router to activate your access.';
                }
            } catch (error) {
                document.getElementById('payment-message').textContent = 'Checking payment status… Keep this page open.';
            }

            setTimeout(checkPaymentStatus, 2000);
        }

        if (paymentStatusUrl) {
            document.addEventListener('DOMContentLoaded', checkPaymentStatus, { once: true });
        } else if (initialMikrotikUrl) {
            document.addEventListener('DOMContentLoaded', () => connectToWifi(initialMikrotikUrl, initialVoucherCode), { once: true });
        }

        function retryActivation() {
            document.getElementById('activation-retry').classList.add('hidden');
            checkPaymentStatus();
        }
    </script>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <div class="portal-card w-full max-w-sm rounded-3xl shadow-xl border p-8 text-center">
        @include('portal.partials.brand')
        <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-6 shadow-inner">
            <svg class="w-10 h-10 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path>
            </svg>
        </div>
        <h1 id="page-title" class="text-2xl font-bold text-gray-900 mb-1">
            {{ $paymentStatusUrl ? 'Waiting for payment…' : 'Payment Successful!' }}
        </h1>
        <p id="payment-message" class="text-sm text-gray-500 mb-5">
            {{ $paymentStatusUrl ? 'Approve the payment prompt on your phone. This page will connect you once the payment is confirmed.' : 'Your Wi-Fi access is now activated.' }}
        </p>

        <div id="voucher-panel" class="voucher-panel mb-5 border rounded-2xl p-4 {{ $voucherCode ? '' : 'hidden' }}">
                <span class="voucher-label text-xs uppercase font-semibold tracking-wider">Your Voucher Code</span>
                <div id="voucher-code" class="voucher-code text-2xl font-extrabold tracking-widest mt-1 select-all font-mono">
                    {{ $voucherCode ?? '' }}
                </div>
                <p class="voucher-note text-[11px] mt-1">Keep this code handy in case your device reconnects.</p>
        </div>
        
        <div id="payment-spinner" class="mb-5 flex items-center justify-center space-x-2 text-xs text-gray-400 {{ $paymentStatusUrl ? '' : 'hidden' }}">
            <div class="animate-spin rounded-full h-4 w-4 border-2 border-primary border-t-transparent"></div>
            <span>Waiting for payment confirmation…</span>
        </div>

        <button id="activation-retry" type="button" onclick="retryActivation()"
                class="hidden mb-4 w-full rounded-xl border border-blue-200 bg-blue-50 px-4 py-2.5 text-sm font-semibold text-blue-700">
            Retry activation
        </button>

        <a id="mikrotik-link" href="{{ $mikrotikUrl ?? '#' }}" class="{{ $mikrotikUrl ? '' : 'hidden' }} inline-block w-full bg-primary text-white font-bold py-3.5 px-6 rounded-xl shadow-md hover:bg-primary-dark transition-all duration-150 text-sm">
            Connect now
        </a>
    </div>
    @include('partials.swal-flash')
    <x-ui.toast :show-initial="false" />
</body>
</html>
