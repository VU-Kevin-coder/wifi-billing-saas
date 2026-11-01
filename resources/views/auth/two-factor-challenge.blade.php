<x-guest-layout>
    <div class="mb-4 text-sm text-gray-600">
        Enter the 6-digit code from your authenticator app or one of your recovery codes to finish signing in.
    </div>

    @if ($errors->any())
        <div class="mb-4 text-sm text-red-600" role="alert">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('two-factor.verify') }}">
        @csrf

        <div>
            <label for="code" class="block font-medium text-sm text-gray-700">Authentication code</label>
            <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" autofocus required
                class="block mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            <p class="mt-1 text-xs text-gray-500">Enter a 6-digit authenticator code or an unused recovery code.</p>
        </div>

        <div class="flex items-center justify-end mt-4">
            <button type="submit" class="ms-3 inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-indigo-500">
                Verify and continue
            </button>
        </div>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-4 text-right">
        @csrf
        <button type="submit" class="underline text-sm text-gray-600 hover:text-gray-900">Log out</button>
    </form>
</x-guest-layout>
