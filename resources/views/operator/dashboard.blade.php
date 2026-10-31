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

                <div class="mt-8 pt-6 border-t border-gray-200 flex items-center justify-between">
                    <p class="text-sm text-gray-500">
                        Manage your network offerings, pricing, and bandwidth limits.
                    </p>
                    <a href="{{ route('operator.packages.index') }}" class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 focus:bg-blue-700 active:bg-blue-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                        Manage Packages &rarr;
                    </a>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
