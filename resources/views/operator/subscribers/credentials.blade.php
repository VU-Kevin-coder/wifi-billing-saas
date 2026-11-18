<x-app-layout>
    <div class="mx-auto max-w-2xl px-4 py-12 sm:px-6">
        <section class="rounded-2xl border border-green-200 bg-white p-6 shadow-sm">
            <div class="flex h-11 w-11 items-center justify-center rounded-full bg-green-100 text-xl text-green-700" aria-hidden="true">✓</div>
            <h1 class="mt-4 text-2xl font-bold text-gray-900">PPPoE credentials created</h1>
            <p class="mt-2 text-sm text-gray-600">Copy and deliver these credentials securely. The password will not be shown again.</p>

            <dl class="mt-6 space-y-4 rounded-xl bg-gray-50 p-5">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Subscriber</dt>
                    <dd class="mt-1 text-sm font-semibold text-gray-900">{{ $subscriber->name }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">PPPoE username</dt>
                    <dd class="mt-1 flex items-center justify-between gap-3">
                        <code id="subscriber-username" class="break-all text-sm text-gray-900">{{ $subscriber->username }}</code>
                        <button type="button" onclick="copyCredential('subscriber-username')" class="shrink-0 text-sm font-medium text-blue-700">Copy</button>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">PPPoE password</dt>
                    <dd class="mt-1 flex items-center justify-between gap-3">
                        <code id="subscriber-password" class="break-all text-sm text-gray-900">{{ $password }}</code>
                        <button type="button" onclick="copyCredential('subscriber-password')" class="shrink-0 text-sm font-medium text-blue-700">Copy</button>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Package expires</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $subscriber->expires_at->format('Y-m-d H:i') }}</dd>
                </div>
            </dl>

            <a href="{{ route('operator.subscribers.index') }}" class="mt-6 inline-flex rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-gray-700">
                Back to subscribers
            </a>
        </section>
    </div>
    <script>
        async function copyCredential(elementId) {
            const value = document.getElementById(elementId)?.innerText;
            if (value) await navigator.clipboard.writeText(value);
        }
    </script>
</x-app-layout>
