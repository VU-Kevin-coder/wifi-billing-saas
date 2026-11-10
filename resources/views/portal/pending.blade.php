<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Processing payment · {{ $tenant->settings['portal_title'] ?: $tenant->name }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet">
    @vite('resources/css/public.css')
    @vite('resources/js/public.js')
    <style>
        :root {
            --primary: {{ $tenant->settings['primary_color'] ?? '#3B82F6' }};
            --primary-dark: color-mix(in srgb, var(--primary), black 18%);
            --paper: #f2eee6;
            --panel: #fbf9f4;
            --ink: #1c1a17;
            --muted: #6f6a60;
            --line: #ddd6c8;
        }
        * { box-sizing: border-box; }
        body { min-height: 100vh; display: grid; place-items: center; margin: 0; padding: 24px 16px; background: var(--paper); color: var(--ink); font-family: 'Figtree', sans-serif; }
        .payment-card { width: min(100%, 410px); padding: 28px; border: 1px solid var(--line); border-radius: 20px; background: var(--panel); text-align: center; box-shadow: 0 18px 40px -24px rgba(48,38,24,.4); }
        .portal-brand { display: flex; align-items: center; gap: 12px; margin-bottom: 28px; text-align: left; }
        .portal-brand-mark { display: grid; width: 48px; height: 48px; flex: 0 0 48px; place-items: center; overflow: hidden; border: 1px solid var(--line); border-radius: 14px; background: white; color: var(--primary); }
        .portal-brand-mark img { width: 100%; height: 100%; padding: 5px; object-fit: contain; }
        .portal-brand-fallback { width: 28px; height: 28px; }
        .portal-brand strong, .portal-brand span { display: block; }
        .portal-brand strong { font-family: Georgia, "Times New Roman", serif; font-size: 1.12rem; font-weight: 600; }
        .portal-brand span { margin-top: 3px; color: var(--muted); font-size: .82rem; }
        .payment-icon { display: grid; width: 72px; height: 72px; place-items: center; margin: 0 auto 22px; border-radius: 50%; background: color-mix(in srgb, var(--primary), white 88%); color: var(--primary); }
        .payment-icon svg { width: 36px; height: 36px; }
        h1 { margin: 0 0 8px; font-family: Georgia, "Times New Roman", serif; font-size: 1.7rem; font-weight: 500; }
        .lede { margin: 0 0 22px; color: var(--muted); font-size: .9rem; line-height: 1.55; }
        #status-message { color: var(--primary); font-size: .87rem; font-weight: 600; }
        #status-message.failed { color: #b42318; }
        .payment-details { margin-top: 23px; color: var(--muted); font-size: .78rem; line-height: 1.6; }
        .return-link { display: inline-block; margin-top: 21px; color: var(--primary); font-size: .86rem; font-weight: 700; text-decoration: none; }
        .return-link:hover { color: var(--primary-dark); text-decoration: underline; }
    </style>
</head>
<body>
    <main class="payment-card">
        @include('portal.partials.brand')
        <div class="payment-icon">
            <svg class="animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
        </div>

        <h1>Waiting for payment</h1>
        <p class="lede">Approve the mobile money prompt on your phone. Keep this page open while we confirm your payment.</p>

        <div id="status-message" role="status" aria-live="polite">
            Checking payment status…
        </div>

        <div class="payment-details">
            <p>Reference: {{ $transaction->reference }}</p>
            <p>Amount: {{ $currency }} {{ number_format((float) $transaction->amount) }}</p>
        </div>

        <a id="return-link" href="{{ $portalUrl }}" class="return-link hidden">
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
                    statusElement.classList.add('failed');
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
