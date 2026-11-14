<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-2 text-sm text-gray-500">
            <a href="{{ route('operator.routers.show', ['tenant' => $tenant->slug, 'router' => $router->id]) }}" class="hover:text-gray-900">{{ $router->name }}</a>
            <span aria-hidden="true">&rsaquo;</span>
            <span class="font-medium text-gray-900">RADIUS credential</span>
        </div>
    </x-slot>

    <div class="min-h-screen bg-gray-50 py-8">
        <div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8">
            <section class="rounded-xl border border-amber-200 bg-white p-6 shadow-sm sm:p-8">
                <h1 class="text-xl font-bold text-gray-900">Router RADIUS secret</h1>
                <p class="mt-2 text-sm text-amber-800" role="alert">
                    This secret is shown once. Copy it to the approved secure configuration process now; it cannot be viewed again from this application.
                </p>
                <p class="mt-2 text-sm text-gray-600">
                    If it is lost, use a separately authorized credential-rotation procedure. This action only configures this router record; it does not change a physical router or FreeRADIUS.
                </p>

                <dl class="mt-6 divide-y divide-gray-100 rounded-lg border border-gray-200 px-4">
                    <div class="py-3">
                        <dt class="text-sm text-gray-500">Router</dt>
                        <dd class="mt-1 text-sm font-medium text-gray-900">{{ $router->name }}</dd>
                    </div>
                    <div class="py-3">
                        <dt class="text-sm text-gray-500">NAS identifier</dt>
                        <dd class="mt-1 break-all font-mono text-sm text-gray-900">{{ $router->radius_nas_identifier }}</dd>
                    </div>
                    <div class="py-3">
                        <dt class="text-sm text-gray-500">RADIUS shared secret</dt>
                        <dd class="mt-1 break-all">
                            <code id="radius-shared-secret" class="select-all rounded bg-gray-100 px-2 py-1 font-mono text-sm text-gray-900">{{ $secret }}</code>
                        </dd>
                    </div>
                </dl>

                <a href="{{ route('operator.routers.show', ['tenant' => $tenant->slug, 'router' => $router->id]) }}"
                   class="mt-6 inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                    Return to router
                </a>
            </section>
        </div>
    </div>
</x-app-layout>
