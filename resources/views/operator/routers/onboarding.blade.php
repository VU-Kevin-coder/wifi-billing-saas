<x-app-layout>
    <div class="min-h-screen bg-gray-950 px-4 py-8 text-white sm:px-6 lg:px-8"
         x-data="routerOnboarding()" x-init="start()">
        <div class="mx-auto max-w-5xl">
            <a href="{{ route('operator.routers.index') }}" class="text-sm text-gray-400 hover:text-white">Mikro Tiks</a>
            <h1 class="mt-3 text-2xl font-bold">Add MikroTik Device</h1>
            <p class="mt-2 text-sm text-gray-400">Run the provisioning command on {{ $router->name }}. The system will detect its secure connection.</p>

            <div class="mt-8 grid grid-cols-3 overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
                <div class="border-b border-gray-800 p-4">
                    <span class="text-sm font-semibold text-gray-400">✓ &nbsp; Connection</span>
                    <p class="mt-1 text-xs text-gray-500">Basic device information</p>
                </div>
                <div class="border-b-2 border-orange-500 p-4">
                    <span class="text-sm font-semibold text-orange-400">02 &nbsp; Device Details</span>
                    <p class="mt-1 text-xs text-gray-400">Provisioning command</p>
                </div>
                <div class="border-b border-gray-800 p-4">
                    <span class="text-sm font-semibold text-gray-500">03 &nbsp; Service Setup</span>
                    <p class="mt-1 text-xs text-gray-500">Configure HotSpot</p>
                </div>
            </div>

            <section class="mt-6 rounded-xl border border-gray-800 bg-gray-900 p-6">
                <div class="flex items-center justify-between border-b border-gray-700 pb-4">
                    <h2 class="font-semibold">Provisioning Command</h2>
                    <button type="button" @click="copyCommand()" class="text-sm font-semibold text-orange-400 hover:text-orange-300">Click to copy</button>
                </div>
                @if ($bootstrapCommand)
                    <pre id="bootstrap-command" class="mt-5 overflow-x-auto rounded-lg bg-gray-950 p-4 text-sm text-blue-200">{{ $bootstrapCommand }}</pre>
                @else
                    <p class="mt-5 rounded-lg border border-amber-800 bg-amber-950 p-4 text-sm text-amber-200">
                        This bootstrap command is unavailable or expired. Restart onboarding to generate a fresh one.
                    </p>
                @endif

                <p class="mt-4 rounded-lg border border-gray-700 bg-gray-950 p-4 text-sm text-gray-300">
                    Paste and execute the command in the MikroTik terminal. It establishes the router's WireGuard tunnel and a management API restricted to that tunnel. Use a fresh or dedicated router and keep an out-of-band recovery path.
                </p>
                <p class="mt-3 text-xs text-gray-400">
                    If RouterOS reports “device mode not allowed”, review MikroTik's device-mode requirements on the router before retrying.
                </p>

                <div class="mt-6 flex items-start gap-3 rounded-lg border border-gray-700 p-4" role="status" aria-live="polite">
                    <span class="mt-1 h-3 w-3 shrink-0 rounded-full"
                          :class="status === 'connected' ? 'bg-green-500' : (status === 'expired' ? 'bg-red-500' : 'animate-pulse bg-orange-500')"></span>
                    <div>
                        <p class="font-mono text-sm" x-text="message"></p>
                        <p class="mt-2 text-xs text-gray-500" x-text="`Attempt ${attempt} of 300`"></p>
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-between">
                    <a href="{{ route('operator.routers.show', ['tenant' => $tenant->slug, 'router' => $router->id]) }}"
                       class="rounded-lg border border-gray-700 px-4 py-2 text-sm text-gray-300 hover:bg-gray-800">Back</a>
                    <a x-show="status === 'connected'" x-cloak
                       href="{{ route('operator.routers.onboarding.services', ['tenant' => $tenant->slug, 'router' => $router->id]) }}"
                       class="rounded-lg bg-orange-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-orange-500">Next</a>
                </div>
            </section>
        </div>
    </div>

    <script>
        function routerOnboarding() {
            return {
                status: @js($onboarding->status),
                message: @js($onboarding->status === 'connected' ? 'Router connection verified.' : 'Paste the provisioning command into your MikroTik terminal to begin.'),
                attempt: 0,
                timer: null,
                async start() {
                    if (this.status === 'connected' || this.status === 'expired') return;
                    this.poll();
                },
                async poll() {
                    if (this.attempt >= 300 || this.status === 'connected' || this.status === 'expired') return;
                    this.attempt++;
                    try {
                        const response = await fetch(@js(route('operator.routers.onboarding.status', ['tenant' => $tenant->slug, 'router' => $router->id])), {
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            credentials: 'same-origin',
                        });
                        const data = await response.json();
                        if (!response.ok) throw new Error(data.message || 'Unable to check router status.');
                        this.status = data.status;
                        this.message = data.message;
                    } catch (error) {
                        this.message = error.message || 'Unable to check router status. Retrying.';
                    }
                    if (this.status !== 'connected' && this.status !== 'expired' && this.attempt < 300) {
                        this.timer = window.setTimeout(() => this.poll(), 3000);
                    }
                },
                async copyCommand() {
                    const command = document.getElementById('bootstrap-command')?.innerText;
                    if (!command) return;
                    await navigator.clipboard.writeText(command);
                    window.toast?.success('Provisioning command copied.');
                },
                destroy() {
                    if (this.timer) window.clearTimeout(this.timer);
                },
            };
        }
    </script>
</x-app-layout>
