<x-layouts.auth
    title="Forgot your password?"
    subtitle="No worries. Enter your email and we'll send you a secure link to set a new one."
>
    <div class="mb-8">
        <h1 class="text-2xl font-bold text-gray-900">Reset your password</h1>
        <p class="text-gray-500 text-sm mt-1">We'll email you a link to choose a new password.</p>
    </div>

    <!-- Session Status (e.g. "link sent!") -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
        @csrf

        <!-- Email -->
        <div>
            <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email address</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}"
                required autofocus
                class="block w-full rounded-xl border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 focus:ring-1 text-sm py-2.5 px-3 outline-none transition @error('email') border-red-400 bg-red-50 @enderror">
            @error('email')
                <p class="text-red-500 text-xs mt-1.5 flex items-center gap-1">
                    <svg class="w-3.5 h-3.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                    {{ $message }}
                </p>
            @enderror
        </div>

        <!-- Submit -->
        <button type="submit"
            class="w-full flex items-center justify-center gap-2 py-2.5 px-4 rounded-xl bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white text-sm font-semibold shadow-sm shadow-blue-200 transition-all focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
            Send Reset Link
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
            </svg>
        </button>
    </form>

    <!-- Back to login -->
    <p class="text-center text-sm text-gray-500 mt-6">
        Remembered your password?
        <a href="{{ route('login') }}" class="font-semibold text-blue-600 hover:text-blue-500">Back to sign in</a>
    </p>
</x-layouts.auth>
