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
            --primary: {{ $tenant->portal_settings['primary_color'] ?? '#d91c5c' }};
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
        <h1 class="text-2xl font-bold text-gray-900 mb-2">Voucher Redeemed!</h1>
        <p class="text-sm text-gray-500 mb-6">Connecting your device to the internet…</p>
        
        <div class="mb-6 flex justify-center">
            <div class="animate-spin rounded-full h-6 w-6 border-2 border-primary border-t-transparent"></div>
        </div>

        <a href="{{ $mikrotikUrl }}" class="inline-block w-full bg-primary text-white font-bold py-3.5 px-6 rounded-xl shadow-md hover:bg-primary-dark transition-all duration-150">
            Click here if not redirected
        </a>
    </div>
</body>
</html>
