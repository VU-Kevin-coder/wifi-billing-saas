<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Two-Factor Authentication</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 bg-green-50 text-green-700 p-4 rounded-lg" role="status">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="mb-4 bg-red-50 text-red-700 p-4 rounded-lg" role="alert">{{ session('error') }}</div>
            @endif
            @if ($errors->any())
                <div class="mb-4 bg-red-50 text-red-700 p-4 rounded-lg" role="alert">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <section class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Status</h3>

                @if ($isEnabled)
                    <p class="text-green-600 font-bold mb-4">Two-Factor Authentication is enabled.</p>
                    <p class="text-sm text-gray-600">Unused recovery codes: {{ $remainingRecoveryCodes }}</p>

                    @if (count($recoveryCodes))
                        <div class="mt-6 rounded-lg border border-amber-300 bg-amber-50 p-4">
                            <h4 class="font-medium text-amber-900">Save your recovery codes now</h4>
                            <p class="text-sm text-amber-800 my-2">These codes are shown only once. Each code can be used once to recover access.</p>
                            <div class="grid grid-cols-2 gap-2 rounded bg-white p-4 font-mono text-sm">
                                @foreach ($recoveryCodes as $code)
                                    <div>{{ $code }}</div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @else
                    <p class="text-red-600 font-bold mb-4">Two-Factor Authentication is not enabled.</p>

                    @if ($qrCode)
                        <div class="mt-6 border-t pt-6">
                            <h4 class="font-medium mb-2">1. Scan this QR code with your authenticator app</h4>
                            <div class="bg-white p-4 border inline-block rounded">
                                {!! $qrCode !!}
                            </div>
                            <p class="text-sm text-gray-500 mt-2">Secret key: <span class="font-mono bg-gray-100 px-2 py-1">{{ $secret }}</span></p>

                            <h4 class="font-medium mt-6 mb-2">2. Enter the 6-digit code to confirm</h4>
                            <form method="POST" action="{{ route('profile.security.confirm') }}" class="flex gap-2">
                                @csrf
                                <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="123456" class="rounded-md border-gray-300 w-32 text-center font-mono" required>
                                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg">Confirm</button>
                            </form>
                        </div>
                    @else
                        <form method="POST" action="{{ route('profile.security.enable') }}">
                            @csrf
                            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg font-medium">Enable 2FA</button>
                        </form>
                    @endif
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
