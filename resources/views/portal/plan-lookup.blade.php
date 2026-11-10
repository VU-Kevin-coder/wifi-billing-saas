<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Check my plan - {{ $tenant->name }}</title>
    @vite('resources/css/public.css')
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4 text-gray-900">
    <main class="w-full max-w-sm bg-white rounded-2xl shadow p-6 space-y-4">
        <div>
            <h1 class="text-lg font-bold">{{ $tenant->name }}</h1>
            <p class="text-sm text-gray-500">Check your plan and renew it.</p>
        </div>

        @if ($errors->any())
            <p class="rounded-lg bg-red-50 text-red-700 text-sm p-3" role="alert">{{ $errors->first() }}</p>
        @endif

        <form method="POST" action="{{ route('portal.plan.lookup', ['tenant' => $tenant->slug], false) }}" class="space-y-3">
            @csrf
            <label for="voucher_code" class="block text-sm font-medium text-gray-700">Your voucher code</label>
            <input id="voucher_code" name="voucher_code" type="text" required maxlength="64" autocomplete="off" autocapitalize="characters"
                   value="{{ old('voucher_code') }}" placeholder="e.g. KL-ABC12345"
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
            <button type="submit" class="w-full rounded-lg bg-blue-600 text-white py-2 text-sm font-semibold">Check my plan</button>
        </form>

        <a href="{{ route('portal.index', ['tenant' => $tenant->slug], false) }}" class="block text-center text-sm text-blue-600">Back to packages</a>
    </main>
</body>
</html>
