<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Platform Administration
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">
                    All Tenants ({{ $tenants->count() }})
                </h3>

                <table class="min-w-full divide-y divide-gray-200">
                    <thead>
                        <tr>
                            <th class="px-4 py-2 text-left text-sm font-medium text-gray-500">Name</th>
                            <th class="px-4 py-2 text-left text-sm font-medium text-gray-500">Slug</th>
                            <th class="px-4 py-2 text-left text-sm font-medium text-gray-500">Status</th>
                            <th class="px-4 py-2 text-left text-sm font-medium text-gray-500">Users</th>
                            <th class="px-4 py-2 text-left text-sm font-medium text-gray-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($tenants as $tenant)
                            <tr>
                                <td class="px-4 py-2">{{ $tenant->name }}</td>
                                <td class="px-4 py-2 text-gray-500">{{ $tenant->slug }}</td>
                                <td class="px-4 py-2">{{ $tenant->status }}</td>
                                <td class="px-4 py-2">{{ $tenant->users_count }}</td>
                                <td class="px-4 py-2">
                                    <a href="{{ route('platform.impersonate', $tenant) }}" class="text-blue-600 hover:underline text-sm font-medium">
                                        Switch into
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
