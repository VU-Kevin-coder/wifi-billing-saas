<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>My plan - {{ $tenant->name }}</title>
    @vite('resources/css/public.css')
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4 text-gray-900">
    <main class="w-full max-w-sm bg-white rounded-2xl shadow p-6 space-y-5">
        <div>
            <h1 class="text-lg font-bold">{{ $tenant->name }}</h1>
            <p class="text-sm text-gray-500">Your internet plan</p>
        </div>

        <section class="rounded-xl border p-4 space-y-1 {{ $active ? 'border-green-200 bg-green-50' : 'border-gray-200 bg-gray-50' }}">
            <p class="text-sm font-semibold">{{ $package?->name ?? 'Internet package' }}</p>
            @if ($active)
                <p class="text-green-700 text-sm font-medium">Active</p>
                <p class="text-sm text-gray-700">
                    @if ($minutesLeft >= 90) About {{ round($minutesLeft / 60) }} hours left
                    @else {{ $minutesLeft }} minute{{ $minutesLeft === 1 ? '' : 's' }} left
                    @endif
                </p>
            @else
                <p class="text-gray-600 text-sm font-medium">Expired</p>
            @endif
            @if ($expiresAt)
                <p class="text-xs text-gray-500">{{ $active ? 'Ends' : 'Ended' }} {{ $expiresAt->format('d M Y, H:i') }}</p>
            @endif
            @if ($package && $package->data_limit && strtolower(trim($package->data_limit)) !== 'unlimited')
                <p class="text-xs text-gray-500">Data allowance: {{ $package->data_limit }}@if ($bytesUsed > 0) (used {{ number_format($bytesUsed / 1073741824, 2) }} GB)@endif</p>
            @endif
        </section>

        @if ($canRenew)
            <form method="POST" action="{{ route('portal.purchase', ['tenant' => $tenant->slug], false) }}" class="space-y-3">
                @csrf
                <input type="hidden" name="_idempotency_key" value="{{ $purchaseKey }}">
                <input type="hidden" name="package_id" value="{{ $package->id }}">
                <label for="phone" class="block text-sm font-medium text-gray-700">Mobile money number</label>
                <input id="phone" name="phone" type="tel" inputmode="tel" required minlength="9" maxlength="15" value="{{ $phone }}"
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                <button type="submit" class="w-full rounded-lg bg-blue-600 text-white py-2 text-sm font-semibold">
                    {{ $active ? 'Renew now' : 'Buy again' }} - {{ $currency }} {{ number_format((float) $package->price, 0) }}
                </button>
            </form>
        @else
            <p class="text-sm text-gray-600">This package is no longer offered.</p>
            <a href="{{ route('portal.index', ['tenant' => $tenant->slug], false) }}" class="block text-center rounded-lg bg-blue-600 text-white py-2 text-sm font-semibold">See available packages</a>
        @endif

        <a href="{{ route('portal.index', ['tenant' => $tenant->slug], false) }}" class="block text-center text-sm text-blue-600">All packages</a>
    </main>
</body>
</html>
