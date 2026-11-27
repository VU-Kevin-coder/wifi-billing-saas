<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Two-Factor Authentication</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="bg-green-50 text-green-700 p-4 rounded-lg" role="status">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="bg-red-50 text-red-700 p-4 rounded-lg" role="alert">{{ session('error') }}</div>
            @endif
            @if ($errors->any())
                <div class="bg-red-50 text-red-700 p-4 rounded-lg" role="alert">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            @if ($mfaRequired)
                <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="status">
                    Your account or workspace requires two-factor authentication. Keep at least one method enabled.
                </div>
            @endif

            <section class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="text-lg font-medium text-gray-900">Your verification methods</h3>
                <p class="mt-1 text-sm text-gray-600">Add more than one way to verify your identity so you can still access your account if you lose a device.</p>

                @if (count($enabledMethods))
                    <ul class="mt-5 space-y-3">
                        @foreach ($enabledMethods as $method)
                            @php
                                $methodNames = ['totp' => 'Authenticator app', 'email' => 'Email code', 'sms' => 'SMS code'];
                            @endphp
                            <li class="flex flex-col gap-3 rounded-lg border border-green-200 bg-green-50 p-4 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="font-medium text-green-900">{{ $methodNames[$method] }}</p>
                                    @if ($method === 'email')
                                        <p class="text-sm text-green-800">{{ auth()->user()->email }}</p>
                                    @elseif ($method === 'sms')
                                        <p class="text-sm text-green-800">{{ auth()->user()->phone }}</p>
                                    @endif
                                </div>
                                @if (! ($mfaRequired && count($enabledMethods) === 1))
                                    <form method="POST" action="{{ route('profile.security.methods.remove', $method) }}" class="flex flex-wrap items-center gap-2">
                                        @csrf
                                        @method('DELETE')
                                        <label class="sr-only" for="remove-password-{{ $method }}">Current password to remove {{ $methodNames[$method] }}</label>
                                        <input
                                            id="remove-password-{{ $method }}"
                                            type="password"
                                            name="password"
                                            autocomplete="current-password"
                                            placeholder="Current password"
                                            required
                                            class="w-44 rounded-md border-gray-300 text-sm"
                                        >
                                        <button type="submit" class="rounded-md border border-red-300 px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50">
                                            Remove
                                        </button>
                                    </form>
                                @else
                                    <span class="text-sm text-gray-600">Required by policy</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-4 text-sm text-gray-600">Unused recovery codes: {{ $remainingRecoveryCodes }}</p>
                @else
                    <p class="mt-4 font-medium text-red-700">No verification method is enabled.</p>
                @endif

                @if (count($recoveryCodes))
                    <div class="mt-6 rounded-lg border border-amber-300 bg-amber-50 p-4">
                        <h4 class="font-medium text-amber-900">Save your recovery codes now</h4>
                        <p class="my-2 text-sm text-amber-800">These codes are shown only once. Each code can be used once to recover access.</p>
                        <div class="grid grid-cols-2 gap-2 rounded bg-white p-4 font-mono text-sm">
                            @foreach ($recoveryCodes as $code)
                                <div>{{ $code }}</div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </section>

            <section class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="text-lg font-medium text-gray-900">Add a verification method</h3>

                <div class="mt-5 space-y-6">
                    <div class="border-b border-gray-200 pb-6">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h4 class="font-medium text-gray-900">Authenticator app</h4>
                                <p class="mt-1 text-sm text-gray-600">Use an authenticator app to generate time-based codes.</p>
                            </div>
                            @if (in_array('totp', $enabledMethods, true))
                                <span class="text-sm font-medium text-green-700">Enabled</span>
                            @elseif ($pendingMethod === 'totp' && $qrCode)
                                <span class="text-sm font-medium text-blue-700">Setup in progress</span>
                            @else
                                <form method="POST" action="{{ route('profile.security.enable') }}">
                                    @csrf
                                    <button type="submit" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                        Set up authenticator
                                    </button>
                                </form>
                            @endif
                        </div>

                        @if ($pendingMethod === 'totp' && $qrCode)
                            <div class="mt-5">
                                <h5 class="font-medium text-gray-900">1. Scan this QR code with your authenticator app</h5>
                                <div class="mt-2 inline-block rounded border bg-white p-4">{!! $qrCode !!}</div>
                                <p class="mt-2 text-sm text-gray-600">Secret key: <span class="rounded bg-gray-100 px-2 py-1 font-mono">{{ $secret }}</span></p>
                                <h5 class="mt-5 font-medium text-gray-900">2. Enter the 6-digit code to confirm</h5>
                                <form method="POST" action="{{ route('profile.security.confirm') }}" class="mt-2 flex flex-wrap gap-2">
                                    @csrf
                                    <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="123456" class="w-32 rounded-md border-gray-300 text-center font-mono" required>
                                    <button type="submit" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Confirm authenticator</button>
                                </form>
                            </div>
                        @endif
                    </div>

                    <div class="border-b border-gray-200 pb-6">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h4 class="font-medium text-gray-900">Email code</h4>
                                <p class="mt-1 text-sm text-gray-600">
                                    @if ($emailVerified)
                                        Send a one-time code to {{ auth()->user()->email }}.
                                    @else
                                        Verify your email address before adding this method.
                                    @endif
                                </p>
                            </div>
                            @if (in_array('email', $enabledMethods, true))
                                <span class="text-sm font-medium text-green-700">Enabled</span>
                            @elseif ($pendingMethod === 'email')
                                <span class="text-sm font-medium text-blue-700">Confirmation code sent</span>
                            @elseif ($emailVerified)
                                <form method="POST" action="{{ route('profile.security.channels.challenge') }}">
                                    @csrf
                                    <input type="hidden" name="method" value="email">
                                    <button type="submit" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Send email code</button>
                                </form>
                            @endif
                        </div>
                        @if ($pendingMethod === 'email')
                            <form method="POST" action="{{ route('profile.security.channels.confirm') }}" class="mt-4 flex flex-wrap gap-2">
                                @csrf
                                <input type="hidden" name="method" value="email">
                                <label class="sr-only" for="email-enrollment-code">Email verification code</label>
                                <input id="email-enrollment-code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="123456" class="w-32 rounded-md border-gray-300 text-center font-mono" required>
                                <button type="submit" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Confirm email</button>
                            </form>
                        @endif
                    </div>

                    <div>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h4 class="font-medium text-gray-900">SMS code</h4>
                                <p class="mt-1 text-sm text-gray-600">
                                    @if ($phoneAvailable)
                                        Send a one-time code to {{ auth()->user()->phone }} to verify this number.
                                    @else
                                        Add a phone number in your <a href="{{ route('profile.edit') }}" class="text-blue-700 underline">profile</a> before adding this method.
                                    @endif
                                </p>
                            </div>
                            @if (in_array('sms', $enabledMethods, true))
                                <span class="text-sm font-medium text-green-700">Enabled</span>
                            @elseif ($pendingMethod === 'sms')
                                <span class="text-sm font-medium text-blue-700">Confirmation code sent</span>
                            @elseif ($phoneAvailable)
                                <form method="POST" action="{{ route('profile.security.channels.challenge') }}">
                                    @csrf
                                    <input type="hidden" name="method" value="sms">
                                    <button type="submit" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Send SMS code</button>
                                </form>
                            @endif
                        </div>
                        @if ($pendingMethod === 'sms')
                            <form method="POST" action="{{ route('profile.security.channels.confirm') }}" class="mt-4 flex flex-wrap gap-2">
                                @csrf
                                <input type="hidden" name="method" value="sms">
                                <label class="sr-only" for="sms-enrollment-code">SMS verification code</label>
                                <input id="sms-enrollment-code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="123456" class="w-32 rounded-md border-gray-300 text-center font-mono" required>
                                <button type="submit" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Confirm SMS</button>
                            </form>
                        @endif
                    </div>
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
