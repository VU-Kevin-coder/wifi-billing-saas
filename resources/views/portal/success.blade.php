<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connected — {{ $tenant->name }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --primary: {{ $tenant->settings['primary_color'] ?? '#3B82F6' }};
            --primary-dark: color-mix(in srgb, var(--primary), black 18%);
            --primary-light: color-mix(in srgb, var(--primary), white 88%);
        }
        body {
            font-family: 'Figtree', sans-serif;
        }
        .bg-primary { background-color: var(--primary); }
        .text-primary { color: var(--primary); }
    </style>
    <script>
        // Auto-redirect to the MikroTik URL after 3 seconds
        setTimeout(function() {
            window.location.href = "{{ $mikrotikUrl }}";
        }, 3000);
    </script>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-sm bg-white rounded-3xl shadow-xl border border-gray-100 p-8 text-center">
        <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-6 shadow-inner">
            <svg class="w-10 h-10 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path>
            </svg>
        </div>
        <h1 class="text-2xl font-bold text-gray-900 mb-1">Payment Successful!</h1>
        <p class="text-sm text-gray-500 mb-5">Your Wi-Fi access is now activated.</p>

        @if(!empty($voucherCode))
            <div class="mb-5 bg-blue-50 border border-blue-200 rounded-2xl p-4">
                <span class="text-xs uppercase font-semibold text-blue-700 tracking-wider">Your Voucher Code</span>
                <div class="text-2xl font-extrabold text-blue-900 tracking-widest mt-1 select-all font-mono">
                    {{ $voucherCode }}
                </div>
                <p class="text-[11px] text-blue-600 mt-1">Keep this code handy in case your device reconnects.</p>
            </div>
        @endif
        
        <div class="mb-5 flex items-center justify-center space-x-2 text-xs text-gray-400">
            <div class="animate-spin rounded-full h-4 w-4 border-2 border-primary border-t-transparent"></div>
            <span>Connecting to Wi-Fi gateway…</span>
        </div>

        <a href="{{ $mikrotikUrl }}" class="inline-block w-full bg-primary text-white font-bold py-3.5 px-6 rounded-xl shadow-md hover:bg-primary-dark transition-all duration-150 text-sm">
            Connect Immediately
        </a>
    </div>
</body>
</html>
