<x-layouts.auth
    title="Confirm it is you."
    subtitle="Sensitive actions need a fresh two-factor check."
>
    <div class="mb-8">
        <h1 class="text-2xl font-bold text-gray-900">Confirm your identity</h1>
        <p class="text-gray-500 text-sm mt-1">Enter the 6-digit code from your authenticator app, or one of your unused recovery codes.</p>
    </div>

    @if ($errors->any())
        <div class="mb-5 text-sm text-red-700 bg-red-50 border border-red-200 px-4 py-3 rounded-xl space-y-1" role="alert">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('mfa.step-up.verify') }}" class="space-y-5">
        @csrf

        <!-- OTP Code -->
        <div>
            <label for="code" class="block text-sm font-medium text-gray-700 mb-1">Authentication code</label>
            <input id="code" name="code" type="text" inputmode="numeric"
                autocomplete="one-time-code" autofocus required maxlength="8"
                placeholder="000000"
                class="block w-full rounded-xl border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 focus:ring-1 text-sm py-2.5 px-3 outline-none tracking-widest font-mono transition @error('code') border-red-400 bg-red-50 @enderror">
            <p class="text-xs text-gray-400 mt-1.5">Enter a 6-digit code or an unused recovery code.</p>
        </div>

        <!-- Verify Button -->
        <button type="submit"
            class="w-full flex items-center justify-center gap-2 py-2.5 px-4 rounded-xl bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white text-sm font-semibold shadow-sm shadow-blue-200 transition-all focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
            Confirm
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
            </svg>
        </button>
    </form>

    <!-- Sign out fallback -->
    <form method="POST" action="{{ route('logout') }}" class="mt-6 text-center">
        @csrf
        <button type="submit" class="text-sm text-gray-400 hover:text-gray-600 underline underline-offset-2 transition">
            Sign out
        </button>
    </form>
</x-layouts.auth>
