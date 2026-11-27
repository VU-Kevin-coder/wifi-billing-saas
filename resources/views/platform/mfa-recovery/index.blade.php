<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">MFA Recovery</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
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

            <section class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="text-lg font-medium text-gray-900">Reset a user's verification methods</h3>
                <p class="mt-2 text-sm text-gray-600">
                    This removes their authenticator, email and SMS factors, recovery codes, pending codes, and remembered devices.
                    The user must enroll again. Platform administrator accounts are intentionally excluded; use the CLI break-glass command for those.
                </p>

                <div class="mt-6 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead>
                            <tr class="text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                <th class="px-4 py-3">User</th>
                                <th class="px-4 py-3">Workspace</th>
                                <th class="px-4 py-3">Reset MFA</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @forelse ($users as $user)
                                <tr>
                                    <td class="px-4 py-4 align-top">
                                        <div class="font-medium text-gray-900">{{ $user->name }}</div>
                                        <div class="text-sm text-gray-500">{{ $user->email }}</div>
                                    </td>
                                    <td class="px-4 py-4 align-top text-sm text-gray-700">{{ $user->tenant?->name ?? 'Platform account' }}</td>
                                    <td class="px-4 py-4">
                                        <form method="POST" action="{{ route('platform.mfa-recovery.reset', $user) }}" class="space-y-2">
                                            @csrf
                                            <label class="sr-only" for="reason-{{ $user->id }}">Reason for resetting {{ $user->email }}'s MFA</label>
                                            <input
                                                id="reason-{{ $user->id }}"
                                                name="reason"
                                                type="text"
                                                minlength="10"
                                                maxlength="500"
                                                required
                                                placeholder="Document the identity recovery reason"
                                                class="w-full max-w-md rounded-md border-gray-300 text-sm"
                                            >
                                            <button type="submit" class="rounded-md bg-red-700 px-3 py-2 text-sm font-medium text-white hover:bg-red-800">
                                                Reset all MFA
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-4 py-6 text-center text-sm text-gray-500">No eligible accounts found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">{{ $users->links() }}</div>
            </section>
        </div>
    </div>
</x-app-layout>
