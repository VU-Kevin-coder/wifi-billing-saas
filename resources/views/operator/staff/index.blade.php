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

            @if ($errors->any())
                <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
                    @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead>
                        <tr>
                            <th class="px-4 py-2 text-left text-sm font-medium text-gray-500">Name</th>
                            <th class="px-4 py-2 text-left text-sm font-medium text-gray-500">Email</th>
                            <th class="px-4 py-2 text-left text-sm font-medium text-gray-500">Role</th>
                            <th class="px-4 py-2 text-left text-sm font-medium text-gray-500">Status</th>
                            <th class="px-4 py-2 text-left text-sm font-medium text-gray-500">Actions</th>
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
                                <td class="px-4 py-2 text-sm">
                                    @if($member->deactivated_at)
                                        <span class="text-red-600">Deactivated</span>
                                    @else
                                        <span class="text-green-700">Active</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2">
                                    @if(! $member->is(auth()->user()))
                                        <div class="flex flex-wrap items-center gap-2">
                                            <form method="POST" action="{{ route('operator.staff.update', ['tenant' => request()->route('tenant'), 'staff' => $member->id]) }}" class="flex items-center gap-1">
                                                @csrf
                                                @method('PATCH')
                                                <label class="sr-only" for="role-{{ $member->id }}">Role for {{ $member->name }}</label>
                                                <select id="role-{{ $member->id }}" name="role" class="rounded-lg border border-gray-300 py-1 text-xs">
                                                    @foreach(['operator_admin', 'operator_finance', 'operator_support', 'operator_network'] as $roleName)
                                                        <option value="{{ $roleName }}" @selected($member->hasRole($roleName))>{{ $roleName }}</option>
                                                    @endforeach
                                                </select>
                                                <button type="submit" class="rounded-lg bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-200">Save</button>
                                            </form>
                                            @if($member->deactivated_at)
                                                <form method="POST" action="{{ route('operator.staff.reactivate', ['tenant' => request()->route('tenant'), 'staff' => $member->id]) }}">
                                                    @csrf
                                                    <button type="submit" class="text-xs font-medium text-green-700 hover:underline">Reactivate</button>
                                                </form>
                                            @else
                                                <form method="POST" action="{{ route('operator.staff.deactivate', ['tenant' => request()->route('tenant'), 'staff' => $member->id]) }}"
                                                      data-confirm-action="Deactivate {{ addslashes($member->name) }}? They are signed out immediately and cannot sign in again.">
                                                    @csrf
                                                    <button type="submit" class="text-xs font-medium text-red-600 hover:underline">Deactivate</button>
                                                </form>
                                            @endif
                                        </div>
                                    @else
                                        <span class="text-xs text-gray-400">This is you</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
