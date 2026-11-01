<x-layouts.auth
    title="Choose a new password."
    subtitle="Make it strong — your network depends on it."
>
    <div class="mb-8">
        <h1 class="text-2xl font-bold text-gray-900">Set new password</h1>
        <p class="text-gray-500 text-sm mt-1">Your new password must be at least 8 characters.</p>
    </div>

    <form method="POST" action="{{ route('password.store') }}" class="space-y-5">
        @csrf

        <!-- Password Reset Token -->
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <!-- Email (pre-filled, read-only) -->
        <div>
            <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email address</label>
            <input id="email" type="email" name="email" value="{{ old('email', $request->email) }}"
                required autocomplete="email"
                class="block w-full rounded-xl border border-gray-300 bg-gray-50 text-sm py-2.5 px-3 text-gray-500 outline-none @error('email') border-red-400 @enderror">
            @error('email')
                <p class="text-red-500 text-xs mt-1.5">{{ $message }}</p>
            @enderror
        </div>

        <!-- New Password -->
        <div>
            <label for="password" class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
            <input id="password" type="password" name="password"
                required autocomplete="new-password"
                class="block w-full rounded-xl border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 focus:ring-1 text-sm py-2.5 px-3 outline-none transition @error('password') border-red-400 bg-red-50 @enderror">
            @error('password')
                <p class="text-red-500 text-xs mt-1.5">{{ $message }}</p>
            @enderror
        </div>

        <!-- Confirm Password -->
        <div>
            <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password</label>
            <input id="password_confirmation" type="password" name="password_confirmation"
                required autocomplete="new-password"
                class="block w-full rounded-xl border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 focus:ring-1 text-sm py-2.5 px-3 outline-none transition">
        </div>

        <!-- Submit -->
        <button type="submit"
            class="w-full flex items-center justify-center gap-2 py-2.5 px-4 rounded-xl bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white text-sm font-semibold shadow-sm shadow-blue-200 transition-all focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
            Reset Password
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
        </button>
    </form>

    <!-- Back to login -->
    <p class="text-center text-sm text-gray-500 mt-6">
        <a href="{{ route('login') }}" class="font-semibold text-blue-600 hover:text-blue-500">Back to sign in</a>
    </p>
</x-layouts.auth>
