<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">System users</h2>
    </x-slot>

    <div class="py-8" x-data="{ tab: 'users', search: '' }">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">System users</h1>
                    <p class="mt-1 text-sm text-gray-500">Manage {{ $tenant->name }} team members and their administrative access.</p>
                </div>
                <a href="{{ route('operator.staff.create', ['tenant' => $tenant->slug]) }}" class="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    New user
                </a>
            </div>

            @if (session('status'))
                <div class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-green-700" role="status">{{ session('status') }}</div>
            @endif

            <div class="mb-6 border-b border-gray-200">
                <nav class="-mb-px flex gap-8" aria-label="Team management">
                    <button type="button" @click="tab = 'users'" :aria-current="tab === 'users' ? 'page' : null" :class="tab === 'users' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'" class="inline-flex items-center gap-2 whitespace-nowrap border-b-2 px-1 py-4 text-sm font-medium">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                        Users
                    </button>
                    <button type="button" @click="tab = 'roles'" :aria-current="tab === 'roles' ? 'page' : null" :class="tab === 'roles' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'" class="inline-flex items-center gap-2 whitespace-nowrap border-b-2 px-1 py-4 text-sm font-medium">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        Roles &amp; permissions
                    </button>
                </nav>
            </div>

            <section x-show="tab === 'users'" class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 p-4">
                    <label class="sr-only" for="team-user-search">Search users</label>
                    <input id="team-user-search" type="search" x-model="search" placeholder="Search name, email, or phone" class="block w-full max-w-md rounded-lg border-gray-200 bg-gray-50 py-2 pl-3 pr-3 text-sm focus:border-blue-500 focus:ring-blue-500">
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-bold uppercase text-gray-500">Name</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-bold uppercase text-gray-500">Email</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-bold uppercase text-gray-500">Phone</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-bold uppercase text-gray-500">Roles</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-bold uppercase text-gray-500">Created</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @forelse ($users as $user)
                                <tr x-show="$el.dataset.search.includes(search.trim().toLowerCase())" data-search="{{ strtolower($user->name.' '.$user->email.' '.($user->phone ?? '')) }}">
                                    <td class="whitespace-nowrap px-6 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="flex h-9 w-9 items-center justify-center rounded-full bg-blue-50 text-xs font-bold text-blue-700">{{ strtoupper(substr($user->name, 0, 2)) }}</div>
                                            <div>
                                                <div class="text-sm font-medium text-gray-900">{{ $user->name }}</div>
                                                @if ($user->is(auth()->user()))
                                                    <span class="mt-0.5 inline-block rounded border border-gray-200 bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-600">YOU</span>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-600">{{ $user->email }}</td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-600">{{ $user->phone ?: '—' }}</td>
                                    <td class="px-6 py-4">
                                        <div class="flex flex-wrap gap-1.5">
                                            @forelse ($user->roles as $role)
                                                <span class="rounded bg-orange-100 px-2 py-1 text-xs font-semibold text-orange-800">{{ ucfirst(str_replace(['operator_', '_'], ['', ' '], $role->name)) }}</span>
                                            @empty
                                                <span class="text-sm text-gray-400">No role assigned</span>
                                            @endforelse
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{{ $user->created_at?->format('n/j/Y') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-6 py-12 text-center text-sm text-gray-500">No team members found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-gray-100 px-6 py-3 text-xs text-gray-500">Showing {{ $users->count() }} {{ \Illuminate\Support\Str::plural('user', $users->count()) }}</div>
            </section>

            <section x-show="tab === 'roles'" x-cloak class="grid grid-cols-1 gap-6 md:grid-cols-2">
                @forelse ($roles as $role)
                    @php
                        $roleTitle = [
                            'operator_admin' => 'Administrator',
                            'operator_finance' => 'Finance & Sales',
                            'operator_support' => 'Customer Support',
                            'operator_network' => 'Network Technician',
                        ][$role->name] ?? ucwords(str_replace('_', ' ', $role->name));
                    @endphp
                    <article class="flex flex-col rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <div class="mb-3 flex items-start justify-between gap-3">
                            <div>
                                <h2 class="text-base font-bold text-gray-900">{{ $roleTitle }}</h2>
                                <p class="mt-1 text-xs text-gray-500">{{ $roleMemberCounts[$role->id] ?? 0 }} assigned {{ \Illuminate\Support\Str::plural('team member', $roleMemberCounts[$role->id] ?? 0) }}</p>
                            </div>
                            <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600">System role</span>
                        </div>

                        <p class="mb-4 text-sm text-gray-600">
                            @switch($role->name)
                                @case('operator_admin') Full workspace access and team administration. @break
                                @case('operator_finance') Billing, payments, packages, and sales operations. @break
                                @case('operator_support') Customer support, vouchers, and active-session assistance. @break
                                @case('operator_network') Routers, network configuration, and live sessions. @break
                                @default Access granted by this system role.
                            @endswitch
                        </p>

                        <div class="mb-6 grid grid-cols-1 gap-2 sm:grid-cols-2">
                            @forelse ($role->permissions as $permission)
                                <div class="rounded border border-gray-100 bg-gray-50 p-2 text-xs">
                                    <div class="truncate font-medium text-gray-800">{{ ucwords(str_replace(['.', '_'], ' ', $permission->name)) }}</div>
                                    <div class="mt-1 text-green-700">Granted</div>
                                </div>
                            @empty
                                <p class="col-span-full rounded border border-dashed border-gray-200 p-3 text-xs text-gray-500">No individual permissions are configured for this role.</p>
                            @endforelse
                        </div>

                        <div class="mt-auto flex items-center justify-between border-t border-gray-100 pt-4">
                            <span class="text-xs text-gray-500">Role assignment</span>
                            <a href="{{ route('operator.staff.create', ['tenant' => $tenant->slug, 'role' => $role->name]) }}" class="text-sm font-medium text-blue-600 hover:text-blue-800">Add {{ $roleTitle }}</a>
                        </div>
                    </article>
                @empty
                    <div class="col-span-full rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500">No roles are currently assigned to this workspace's users.</div>
                @endforelse
            </section>
        </div>
    </div>
</x-app-layout>
