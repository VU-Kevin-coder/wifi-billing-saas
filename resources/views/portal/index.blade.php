<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $tenant->name }} - Connect</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        :root {
            --primary: {{ $tenant->settings['primary_color'] ?? '#3B82F6' }};
            --primary-dark: color-mix(in srgb, var(--primary), black 15%);
            --primary-light: color-mix(in srgb, var(--primary), white 88%);
        }
        body { font-family: 'Figtree', sans-serif; }
        .bg-primary { background-color: var(--primary); }
        .text-primary { color: var(--primary); }
        .border-primary { border-color: var(--primary); }
        .bg-primary-dark { background-color: var(--primary-dark); }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4" x-data="{ 
    buyingPackageId: null,
    openBuy(id) { this.buyingPackageId = (this.buyingPackageId === id ? null : id); }
}">

    <div class="w-full max-w-sm bg-white rounded-3xl shadow-xl overflow-hidden border border-gray-100 my-4">
        
        <!-- Header Banner -->
        <div class="bg-primary pt-10 pb-6 px-6 text-center rounded-b-3xl shadow-sm relative overflow-hidden"
             style="background: linear-gradient(180deg, var(--primary) 0%, var(--primary-dark) 100%);">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-white/20 rounded-full mb-4 backdrop-blur-sm shadow-inner">
                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"></path>
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-white tracking-tight">{{ strtolower($tenant->settings['portal_title'] ?? $tenant->name) }}</h1>
            <p class="text-white/80 text-xs font-medium mt-1">{{ $tenant->settings['portal_subtitle'] ?? 'Connect to the internet' }}</p>
        </div>

        <div class="p-6 space-y-4">

            <!-- Flash Alerts -->
            @if(session('status'))
                <div class="p-3 bg-blue-50 border border-blue-200 rounded-xl text-blue-800 text-xs text-center font-medium shadow-sm">
                    {{ session('status') }}
                </div>
            @endif

            @if($errors->any())
                <div class="p-3 bg-red-50 border border-red-200 rounded-xl text-red-700 text-xs text-center font-medium shadow-sm">
                    {{ $errors->first() }}
                </div>
            @endif

            <h2 class="text-center text-xs font-bold text-gray-500 tracking-wider">HAVE A VOUCHER?</h2>
            
            <!-- 1. VOUCHER FORM (Customer enters only voucher code, no phone number) -->
            <form method="POST" action="{{ route('portal.redeem', ['tenant' => $tenant->slug], false) }}">
                @csrf
                @if($routerId)
                    <input type="hidden" name="router_id" value="{{ $routerId }}">
                @endif
                @if($routerIdentity)
                    <input type="hidden" name="router" value="{{ $routerIdentity }}">
                @endif
                <div class="relative mb-3">
                    <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                        <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"></path>
                        </svg>
                    </div>
                    <input type="text" 
                           name="voucher_code" 
                           placeholder="Enter voucher code" 
                           required 
                           autocomplete="off"
                           autocapitalize="characters"
                           class="block w-full pl-10 pr-3 py-3 border border-gray-200 rounded-xl text-sm font-semibold tracking-wider text-gray-800 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 bg-gray-50 @error('voucher_code') border-red-500 @enderror">
                </div>

                <button type="submit" class="w-full bg-primary text-white py-3 rounded-xl font-bold text-sm hover:bg-primary-dark transition-all duration-150 shadow-md active:scale-[0.99]">
                    Connect
                </button>
            </form>

            <div class="relative flex items-center py-2">
                <div class="flex-grow border-t border-gray-200"></div>
                <span class="flex-shrink-0 mx-3 text-xs text-gray-400 font-medium">or purchase a package</span>
                <div class="flex-grow border-t border-gray-200"></div>
            </div>

            <!-- 2. PACKAGES LIST (Buy Online via Mobile Money) -->
            <div class="border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
                <div class="bg-gray-50 px-4 py-2 flex justify-between text-xs font-bold text-gray-600 tracking-wider">
                    <span>PACKAGE</span>
                    <span>PRICE</span>
                </div>
                
                <div class="divide-y divide-gray-100 bg-white">
                    @forelse($packages as $pkg)
                    <div>
                        <!-- Package Row -->
                        <div class="px-4 py-3 flex justify-between items-center" x-show="buyingPackageId !== {{ $pkg->id }}">
                            <div>
                                <p class="font-bold text-gray-900 text-sm leading-tight">{{ $pkg->name }}</p>
                                <p class="text-xs text-gray-500 mt-0.5">{{ $pkg->duration_value }} {{ ucfirst($pkg->duration_unit) }}</p>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="font-bold text-gray-900 text-sm">Ush {{ number_format($pkg->price) }}</span>
                                <button type="button" @click="openBuy({{ $pkg->id }})" class="bg-primary hover:bg-primary-dark text-white text-xs font-bold px-3.5 py-1.5 rounded-lg shadow-sm transition-colors">
                                    BUY
                                </button>
                            </div>
                        </div>

                        <!-- Inline Phone Input (Opens when clicking BUY) -->
                        <div x-show="buyingPackageId === {{ $pkg->id }}" x-cloak class="px-4 py-3 bg-blue-50/60 border-t border-blue-100">
                            <form method="POST" action="{{ route('portal.purchase', ['tenant' => $tenant->slug], false) }}">
                                @csrf
                                <input type="hidden" name="package_id" value="{{ $pkg->id }}">
                                @if($routerId)
                                    <input type="hidden" name="router_id" value="{{ $routerId }}">
                                @endif
                                @if($routerIdentity)
                                    <input type="hidden" name="router" value="{{ $routerIdentity }}">
                                @endif
                                <div class="flex items-center justify-between mb-1.5">
                                    <label class="block text-xs font-semibold text-gray-800">
                                        Pay Ush {{ number_format($pkg->price) }} via mobile money:
                                    </label>
                                    <button type="button" @click="buyingPackageId = null" class="text-xs text-gray-400 hover:text-gray-600 font-bold">
                                        ✕
                                    </button>
                                </div>
                                <div class="flex gap-2">
                                    <input type="text" 
                                           name="phone" 
                                           placeholder="077XXXXXXX" 
                                           required 
                                           class="block w-full rounded-xl border border-gray-300 text-xs px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono">
                                    <button type="submit" class="bg-primary hover:bg-primary-dark text-white text-xs font-bold px-4 py-2 rounded-xl shadow-sm transition-colors shrink-0">
                                        Pay
                                    </button>
                                </div>
                                <label class="mt-2 block text-[10px] font-medium text-gray-700" for="checkout-email-{{ $pkg->id }}">Email (optional)</label>
                                <input id="checkout-email-{{ $pkg->id }}" type="email" name="email" autocomplete="email" placeholder="you@example.com"
                                       class="mt-1 block w-full rounded-xl border border-gray-300 text-xs px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <p class="text-[10px] text-gray-500 mt-1">If omitted, the operator's contact email is sent to Flutterwave. A payment prompt will be sent to your MTN or Airtel number.</p>
                            </form>
                        </div>
                    </div>
                    @empty
                        <div class="p-4 text-center text-xs text-gray-500">
                            No packages currently published.
                        </div>
                    @endforelse
                </div>
            </div>

            <!-- Support Info -->
            <div class="bg-gray-50 rounded-2xl p-3 text-center text-xs text-gray-600">
                Need help? Call <span class="font-bold text-primary">{{ $tenant->settings['support_phone'] ?? '+256 700000000' }}</span>
            </div>

            <div class="text-center text-[11px] text-gray-400">
                Powered by <span class="font-semibold text-gray-500">{{ config('app.name') }}</span> &copy; {{ date('Y') }}
            </div>

        </div>
    </div>

</body>
</html>
