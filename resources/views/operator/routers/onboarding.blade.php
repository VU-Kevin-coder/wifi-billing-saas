<x-app-layout>
    <div class="min-h-screen bg-gray-50 px-4 py-8 text-gray-900 sm:px-6 lg:px-8"
         x-data="routerOnboarding()" x-init="start()">
        <div class="mx-auto max-w-5xl">
            <a href="{{ route('operator.routers.index') }}" class="text-sm text-gray-500 hover:text-gray-800">Mikro Tiks</a>
            <h1 class="mt-3 text-2xl font-bold text-gray-900">Add MikroTik Device</h1>
            <p class="mt-2 text-sm text-gray-600">Run the three commands in order on {{ $router->name }}. The system will detect its secure connection.</p>

            <div class="mt-8 grid grid-cols-3 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 p-4">
                    <span class="text-sm font-semibold text-gray-600">✓ &nbsp; Connection</span>
                    <p class="mt-1 text-xs text-gray-500">Basic device information</p>
                </div>
                <div class="border-b-2 border-orange-500 p-4">
                    <span class="text-sm font-semibold text-orange-700">02 &nbsp; Device Details</span>
                    <p class="mt-1 text-xs text-gray-500">Provisioning command</p>
                </div>
                <div class="border-b border-gray-200 p-4">
                    <span class="text-sm font-semibold text-gray-600">03 &nbsp; Service Setup</span>
                    <p class="mt-1 text-xs text-gray-500">Configure HotSpot</p>
                </div>
            </div>

            <section class="mt-6 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between border-b border-gray-200 pb-4">
                    <h2 class="font-semibold">RouterOS Commands</h2>
                    <button type="button" @click="copyCommand()" class="text-sm font-semibold text-orange-700 hover:text-orange-800">Copy commands</button>
                </div>
                @if (session('status'))
                    <p class="mt-5 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800" role="status">
                        {{ session('status') }}
                    </p>
                @endif
                @if ($errors->any())
                    <p class="mt-5 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700" role="alert">
                        {{ $errors->first() }}
                    </p>
                @endif
                @if ($bootstrapCommand)
                    <pre id="bootstrap-command" class="mt-5 overflow-x-auto rounded-lg bg-gray-100 p-4 text-sm text-blue-900">{{ $bootstrapCommand }}</pre>
                @elseif ($onboarding->status !== 'awaiting_bootstrap')
                    <p class="mt-5 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
                        Router onboarding status: {{ str_replace('_', ' ', $onboarding->status) }}. Continue when WireGuard, API-SSL, and RADIUS checks are complete.
                    </p>
                @else
                    <p class="mt-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                        This bootstrap command is unavailable or expired. Generate a new enrollment token before retrying.
                    </p>
                @endif

                <p class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
                    Run these three commands one at a time, in order, in the MikroTik terminal. They download and import the one-time configuration, then remove the downloaded file. The configuration establishes the router's WireGuard tunnel and a management API restricted to that tunnel. Use a fresh or dedicated router and keep an out-of-band recovery path.
                </p>
                @if ($onboarding->status === 'awaiting_bootstrap' && ! $router->enrolled)
                    <form method="POST" action="{{ route('operator.routers.onboarding.reissue', ['tenant' => $tenant->slug, 'router' => $router->id]) }}" class="mt-5 rounded-lg border border-amber-200 bg-amber-50 p-4">
                        @csrf
                        <label class="flex items-start gap-3 text-sm text-amber-900">
                            <input type="checkbox" name="confirm_router_reset" value="1" required class="mt-1 rounded border-amber-400">
                            <span>I have reset the MikroTik in Winbox and am ready to replace its old WireGuard peer and bootstrap token.</span>
                        </label>
                        <button type="submit" class="mt-4 rounded-lg bg-amber-700 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-800">
                            Issue a fresh onboarding command
                        </button>
                    </form>
                @endif
                <p class="mt-3 text-xs text-gray-600">
                    If RouterOS reports “device mode not allowed”, review MikroTik's device-mode requirements on the router before retrying.
                </p>

                <div class="mt-6 flex items-start gap-3 rounded-lg border border-gray-200 bg-gray-50 p-4" role="status" aria-live="polite">
                    <span class="mt-1 h-3 w-3 shrink-0 rounded-full"
                          :class="['radius_ready', 'complete'].includes(status) ? 'bg-green-500' : (status === 'expired' ? 'bg-red-500' : 'animate-pulse bg-orange-500')"></span>
                    <div>
                        <p class="font-mono text-sm" x-text="message"></p>
                        <p class="mt-2 text-xs text-gray-500" x-text="`Attempt ${attempt} of 300`"></p>
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-between">
                    <a href="{{ route('operator.routers.show', ['tenant' => $tenant->slug, 'router' => $router->id]) }}"
                       class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Back</a>
                    <a x-show="['radius_ready', 'complete'].includes(status)" x-cloak
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
                message: @js(in_array($onboarding->status, ['radius_ready', 'complete'], true)
                    ? 'Router connection and RADIUS readiness verified.'
                    : ($onboarding->status === 'awaiting_bootstrap'
                        ? 'Run the three RouterOS commands in order to begin.'
                        : 'Router onboarding is in progress.')),
                attempt: 0,
                timer: null,
                async start() {
                    if (['radius_ready', 'complete', 'expired'].includes(this.status)) return;
                    this.poll();
                },
                async poll() {
                    if (this.attempt >= 300 || ['radius_ready', 'complete', 'expired'].includes(this.status)) return;
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
                    if (!['radius_ready', 'complete', 'expired'].includes(this.status) && this.attempt < 300) {
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
