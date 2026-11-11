<x-layouts.auth
    title="Set up your Wi-Fi business."
    subtitle="Join ISPs managing their networks, selling vouchers, and tracking revenue with Hamnet."
>
    <div class="mb-7">
        <h1 class="text-2xl font-bold text-gray-900">Create your account</h1>
        <p class="text-gray-500 text-sm mt-1">Start free — no credit card required.</p>
    </div>

    @if (session('email_code_sent'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">
            {{ session('email_code_sent') }}
        </div>
    @endif

    <form method="POST" action="{{ route('register') }}" class="space-y-4">
        @csrf

        {{-- ── Business Name ──────────────────────────────────────────────── --}}
        <div>
            <label for="business_name" class="block text-sm font-medium text-gray-700 mb-1">Business Name</label>
            <input id="business_name" type="text" name="business_name" value="{{ old('business_name') }}"
                required autofocus placeholder="e.g. Kampala Connect"
                class="block w-full rounded-xl border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 focus:ring-1 text-sm py-2.5 px-3 outline-none transition @error('business_name') border-red-400 bg-red-50 @enderror">
            @error('business_name')
                <p class="text-red-500 text-xs mt-1.5">{{ $message }}</p>
            @enderror
        </div>

        {{-- ── Subdomain ──────────────────────────────────────────────────── --}}
        <div>
            <label for="subdomain" class="block text-sm font-medium text-gray-700 mb-1">Portal Subdomain</label>
            <div class="flex rounded-xl shadow-sm overflow-hidden border border-gray-300 focus-within:border-blue-500 focus-within:ring-1 focus-within:ring-blue-500 transition @error('subdomain') border-red-400 bg-red-50 @enderror">
                <input id="subdomain" type="text" name="subdomain" value="{{ old('subdomain') }}"
                    required placeholder="acme"
                    class="flex-1 min-w-0 block w-full border-0 text-sm py-2.5 px-3 outline-none bg-transparent">
                <span class="inline-flex items-center px-3 bg-gray-50 text-gray-400 text-sm border-l border-gray-300 whitespace-nowrap">
                    .hamnet.com
                </span>
            </div>
            @error('subdomain')
                <p class="text-red-500 text-xs mt-1.5">{{ $message }}</p>
            @enderror
        </div>

        {{-- ── Full Name ──────────────────────────────────────────────────── --}}
        <div>
            <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Your Full Name</label>
            <input id="name" type="text" name="name" value="{{ old('name') }}"
                required
                class="block w-full rounded-xl border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 focus:ring-1 text-sm py-2.5 px-3 outline-none transition @error('name') border-red-400 bg-red-50 @enderror">
            @error('name')
                <p class="text-red-500 text-xs mt-1.5">{{ $message }}</p>
            @enderror
        </div>

        {{-- ── Email ──────────────────────────────────────────────────────── --}}
        <div>
            <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email address</label>
            <div class="flex gap-2">
                <input id="email" type="email" name="email" value="{{ old('email') }}"
                    required autocomplete="email"
                    class="min-w-0 flex-1 rounded-xl border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 focus:ring-1 text-sm py-2.5 px-3 outline-none transition @error('email') border-red-400 bg-red-50 @enderror">
                <button type="submit" formmethod="POST" formaction="{{ route('register.email-code') }}" formnovalidate
                    class="shrink-0 rounded-xl border border-blue-200 bg-blue-50 px-3 text-xs font-semibold text-blue-700 hover:bg-blue-100">
                    Verify email
                </button>
            </div>
            @error('email')
                <p class="text-red-500 text-xs mt-1.5">{{ $message }}</p>
            @enderror
        </div>

        {{-- ── Email Verification Code ───────────────────────────────────── --}}
        <div>
            <label for="email_code" class="block text-sm font-medium text-gray-700 mb-1">Email verification code</label>
            <input id="email_code" type="text" name="email_code" value="{{ old('email_code') }}"
                required inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}"
                placeholder="6-digit code"
                class="block w-full rounded-xl border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 focus:ring-1 text-sm py-2.5 px-3 outline-none transition @error('email_code') border-red-400 bg-red-50 @enderror">
            @error('email_code')
                <p class="text-red-500 text-xs mt-1.5">{{ $message }}</p>
            @enderror
        </div>

        {{-- ── Password ───────────────────────────────────────────────────── --}}
        <div>
            <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
            <input id="password" type="password" name="password"
                required autocomplete="new-password"
                class="block w-full rounded-xl border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 focus:ring-1 text-sm py-2.5 px-3 outline-none transition @error('password') border-red-400 bg-red-50 @enderror">
            @error('password')
                <p class="text-red-500 text-xs mt-1.5">{{ $message }}</p>
            @enderror
        </div>

        {{-- ── Confirm Password ───────────────────────────────────────────── --}}
        <div>
            <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-1">Confirm Password</label>
            <input id="password_confirmation" type="password" name="password_confirmation"
                required autocomplete="new-password"
                class="block w-full rounded-xl border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 focus:ring-1 text-sm py-2.5 px-3 outline-none transition">
        </div>

        {{-- ── Submit ─────────────────────────────────────────────────────── --}}
        <button type="submit"
            class="w-full flex items-center justify-center gap-2 py-2.5 px-4 rounded-xl bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white text-sm font-semibold shadow-sm shadow-blue-200 transition-all focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 mt-2">
            Get Started Free
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
            </svg>
        </button>
    </form>

    <!-- Sign in link -->
    <p class="text-center text-sm text-gray-500 mt-6">
        Already have an account?
        <a href="{{ route('login') }}" class="font-semibold text-blue-600 hover:text-blue-500">Sign in</a>
    </p>
</x-layouts.auth>
