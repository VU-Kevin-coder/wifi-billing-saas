<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Staff Management
            </h2>
            @if(auth()->user()->hasRole('operator_admin') || (auth()->user()->hasRole('platform_admin') && session()->has('impersonate_tenant')))
                <a href="{{ route('operator.staff.create') }}" class="bg-blue-600 text-white px-4 py-2 rounded-md text-sm font-medium hover:bg-blue-700">
                    + Add Staff
                </a>
            @endif
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if(session('status'))
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
                    {{ session('status') }}
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead>
                        <tr>
                            <th class="px-4 py-2 text-left text-sm font-medium text-gray-500">Name</th>
                            <th class="px-4 py-2 text-left text-sm font-medium text-gray-500">Email</th>
                            <th class="px-4 py-2 text-left text-sm font-medium text-gray-500">Role</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($staff as $member)
                            <tr>
                                <td class="px-4 py-2">{{ $member->name }}</td>
                                <td class="px-4 py-2 text-gray-500">{{ $member->email }}</td>
                                <td class="px-4 py-2">
                                    <span class="px-2 py-1 bg-gray-100 text-gray-800 text-xs rounded-full">
                                        {{ $member->getRoleNames()->first() }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
