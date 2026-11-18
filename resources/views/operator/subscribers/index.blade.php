<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">PPPoE Subscribers</h2>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">PPPoE subscribers</h1>
            <p class="mt-1 text-sm text-gray-600">Create subscriber credentials for PPPoE-enabled packages. Passwords are shown once and encrypted at rest.</p>
        </div>

        @if ($errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700" role="alert">
                {{ $errors->first() }}
            </div>
        @endif

        <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="font-semibold text-gray-900">Create subscriber</h2>
            @if ($packages->isEmpty())
                <p class="mt-3 rounded-lg bg-amber-50 p-4 text-sm text-amber-900">Create a package with the PPPoE feature before adding subscribers.</p>
            @else
                <form method="POST" action="{{ route('operator.subscribers.store') }}" class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @csrf
                    <input type="hidden" name="_idempotency_key" value="{{ \Illuminate\Support\Str::random(40) }}">
                    <div>
                        <label for="subscriber_name" class="mb-1 block text-sm font-medium text-gray-700">Subscriber name</label>
                        <input id="subscriber_name" name="name" value="{{ old('name') }}" required maxlength="255"
                               class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="subscriber_phone" class="mb-1 block text-sm font-medium text-gray-700">Phone (optional)</label>
                        <input id="subscriber_phone" name="phone" value="{{ old('phone') }}" maxlength="50"
                               class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="subscriber_package" class="mb-1 block text-sm font-medium text-gray-700">PPPoE package</label>
                        <select id="subscriber_package" name="package_id" required
                                class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">Choose package</option>
                            @foreach ($packages as $package)
                                <option value="{{ $package->id }}" @selected(old('package_id') == $package->id)>
                                    {{ $package->name }} — {{ $package->speed_limit_mbps }} Mbps / {{ $package->duration_value }} {{ $package->duration_unit }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="w-full rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">
                            Create credentials
                        </button>
                    </div>
                </form>
            @endif
        </section>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-200 px-5 py-4">
                <h2 class="font-semibold text-gray-900">Subscriber accounts</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-5 py-3">Subscriber</th>
                            <th class="px-5 py-3">Username</th>
                            <th class="px-5 py-3">Package</th>
                            <th class="px-5 py-3">Status</th>
                            <th class="px-5 py-3">Expires</th>
                            <th class="px-5 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($subscribers as $subscriber)
                            <tr>
                                <td class="px-5 py-4">
                                    <p class="font-medium text-gray-900">{{ $subscriber->name }}</p>
                                    <p class="text-xs text-gray-500">{{ $subscriber->phone ?: 'No phone recorded' }}</p>
                                </td>
                                <td class="px-5 py-4 font-mono text-gray-700">{{ $subscriber->username }}</td>
                                <td class="px-5 py-4 text-gray-700">{{ $subscriber->package?->name ?? 'Package removed' }}</td>
                                <td class="px-5 py-4">
                                    <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $subscriber->status === 'active' && $subscriber->expires_at->isFuture() ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-600' }}">
                                        {{ $subscriber->status === 'active' && $subscriber->expires_at->isFuture() ? 'Active' : ($subscriber->status === 'suspended' ? 'Suspended' : 'Expired') }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-gray-600">{{ $subscriber->expires_at->format('Y-m-d H:i') }}</td>
                                <td class="px-5 py-4 text-right">
                                    <form method="POST" action="{{ route('operator.subscribers.update', $subscriber) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="_idempotency_key" value="{{ \Illuminate\Support\Str::random(40) }}">
                                        <input type="hidden" name="status" value="{{ $subscriber->status === 'active' ? 'suspended' : 'active' }}">
                                        <button class="text-sm font-medium text-blue-700 hover:text-blue-900">
                                            {{ $subscriber->status === 'active' ? 'Suspend' : 'Activate' }}
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-10 text-center text-gray-500">No PPPoE subscribers created yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-gray-100 px-5 py-4">{{ $subscribers->links() }}</div>
        </section>
    </div>
</x-app-layout>
