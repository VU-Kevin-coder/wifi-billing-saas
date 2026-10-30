<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Operator Dashboard — {{ $tenant->name }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">
                    Welcome, {{ auth()->user()->name }}!
                </h3>

                <dl class="grid grid-cols-2 gap-4">
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Your Role</dt>
                        <dd class="mt-1 text-lg text-gray-900">
                            {{ auth()->user()->getRoleNames()->first() }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Staff in {{ $tenant->name }}</dt>
                        <dd class="mt-1 text-lg text-gray-900">{{ $userCount }}</dd>
                    </div>
                </dl>

                <p class="mt-8 text-sm text-gray-500">
                    Package, voucher, and payment modules coming in the next steps.
                </p>
            </div>
        </div>
    </div>
</x-app-layout>
