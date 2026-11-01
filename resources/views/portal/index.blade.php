<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $tenant->name }} — Connect to Internet</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        :root {
            --primary: {{ $tenant->settings['primary_color'] ?? '#3B82F6' }};
            --primary-dark: color-mix(in srgb, var(--primary), black 18%);
            --primary-light: color-mix(in srgb, var(--primary), white 88%);
            --primary-tint: color-mix(in srgb, var(--primary), white 95%);
            --primary-border: color-mix(in srgb, var(--primary), white 70%);
            --primary-text: color-mix(in srgb, var(--primary), black 30%);
        }
        body {
            font-family: 'Figtree', sans-serif;
            background-color: var(--primary-tint);
        }
        .bg-primary { background-color: var(--primary); }
        .bg-primary-dark { background-color: var(--primary-dark); }
        .bg-primary-light { background-color: var(--primary-light); }
        .bg-primary-tint { background-color: var(--primary-tint); }
        .text-primary { color: var(--primary); }
        .text-primary-text { color: var(--primary-text); }
        .border-primary { border-color: var(--primary); }
        .border-primary-border { border-color: var(--primary-border); }
        .focus-ring-primary:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary), transparent 75%);
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-3 sm:p-6" x-data="{ showIssues: false }">

    <div class="w-full max-w-[390px] bg-white rounded-[32px] shadow-2xl overflow-hidden border border-pink-100 my-4">
        
        <!-- Header Banner -->
        <div class="bg-primary pt-8 pb-6 px-6 text-center rounded-b-[28px] shadow-sm relative overflow-hidden"
             style="background: linear-gradient(180deg, var(--primary) 0%, var(--primary-dark) 100%);">
            
            <!-- Subtle decorative circles -->
            <div class="absolute -top-10 -right-10 w-32 h-32 rounded-full bg-white/5 pointer-events-none"></div>
            <div class="absolute -bottom-10 -left-10 w-28 h-28 rounded-full bg-white/5 pointer-events-none"></div>

            @if (!empty($tenant->settings['logo_path']))
                <img src="{{ asset('storage/' . $tenant->settings['logo_path']) }}"
                     alt="{{ $tenant->settings['brand_name'] }} logo"
                     class="max-h-16 max-w-36 object-contain mx-auto mb-3 rounded bg-white/90 p-2">
            @else
                <div class="inline-flex items-center justify-center w-14 h-14 bg-white/20 rounded-full mb-3 backdrop-blur-sm shadow-inner">
                    <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"></path>
                    </svg>
                </div>
            @endif
            <h1 class="text-2xl font-bold text-white tracking-tight">{{ strtolower($tenant->settings['portal_title'] ?? $tenant->name) }}</h1>
            <p class="text-white/80 text-xs font-medium mt-1">{{ $tenant->settings['portal_subtitle'] ?? 'Connect to the internet' }}</p>
        </div>

        <div class="p-5 sm:p-6 space-y-4">
            
            <h2 class="text-center text-[11px] font-bold text-primary-text tracking-widest uppercase">HAVE A VOUCHER?</h2>
            
            <!-- Voucher Form -->
            <form method="POST" action="{{ route('portal.redeem', ['tenant' => $tenant->slug]) }}">
                @csrf
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
                           class="block w-full pl-10 pr-3 py-3 border border-primary-border rounded-xl text-sm font-semibold tracking-wider text-gray-800 placeholder-gray-400 focus-ring-primary bg-primary-tint/40 @error('voucher_code') border-red-500 @enderror">
                </div>
                
                @error('voucher_code')
                    <div class="mb-3 px-3 py-2 bg-red-50 border border-red-200 rounded-lg text-red-600 text-xs text-center font-medium">
                        {{ $message }}
                    </div>
                @enderror

                <button type="submit" 
                        class="w-full bg-primary text-white py-3.5 rounded-xl font-bold text-sm hover:bg-primary-dark transition-all duration-150 shadow-md shadow-pink-500/20 active:scale-[0.99]">
                    Connect
                </button>
            </form>

            <!-- Already bought button -->
            <div>
                <a href="#packages-list" 
                   class="w-full bg-white border-2 border-primary text-primary py-2.5 rounded-xl font-bold text-sm flex items-center justify-center gap-1.5 hover:bg-primary-light/40 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    Already bought? Find your voucher
                </a>
            </div>

            @if($tenant->settings['show_packages'] ?? true)
            <!-- Divider -->
            <div class="relative flex items-center py-1">
                <div class="flex-grow border-t border-primary-border/60"></div>
                <span class="flex-shrink-0 mx-3 text-xs font-medium text-primary-text/70">or purchase a package</span>
                <div class="flex-grow border-t border-primary-border/60"></div>
            </div>

            <!-- Packages List -->
            <div id="packages-list" class="border border-primary-border/60 rounded-2xl overflow-hidden shadow-sm">
                <div class="bg-primary-light/60 px-4 py-2.5 flex justify-between text-[11px] font-bold text-primary-text tracking-wider uppercase">
                    <span>PACKAGE</span>
                    <span>PRICE</span>
                </div>
                <div class="divide-y divide-primary-border/40 bg-white">
                    @forelse($packages as $pkg)
                        <div class="px-4 py-3 flex justify-between items-center hover:bg-primary-tint/50 transition-colors">
                            <div>
                                <p class="font-bold text-gray-900 text-sm leading-tight">{{ $pkg->name }}</p>
                                <p class="text-xs text-gray-500 mt-0.5">{{ $pkg->duration_value }} {{ ucfirst($pkg->duration_unit) }}</p>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="font-bold text-gray-900 text-sm">USh {{ number_format($pkg->price) }}</span>
                                <button type="button" 
                                        onclick="alert('Select your mobile money payment method or visit a nearby vendor for voucher: {{ $pkg->name }} (USh {{ number_format($pkg->price) }})')"
                                        class="bg-primary hover:bg-primary-dark text-white text-xs font-bold px-3.5 py-1.5 rounded-lg shadow-sm transition-colors active:scale-95">
                                    BUY
                                </button>
                            </div>
                        </div>
                    @empty
                        <div class="p-4 text-center text-xs text-gray-500">
                            No packages currently published.
                        </div>
                    @endforelse
                </div>
            </div>
            @endif

            @if($tenant->settings['show_device_registration'] ?? true)
            <!-- Smart TV / Console helper box -->
            <div class="bg-primary-light/50 border border-primary-border/70 rounded-2xl p-3 flex items-start gap-2.5">
                <div class="mt-0.5 text-primary">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                    </svg>
                </div>
                <div class="text-xs text-gray-700 leading-snug">
                    Smart TV or Console? 
                    <a href="#" class="font-bold text-primary underline">Buy for TV / another device</a>
                    or 
                    <a href="#" class="font-bold text-primary underline">Register here</a>
                </div>
            </div>
            @endif

            @if($tenant->settings['show_troubleshooting'] ?? true)
            <!-- Connection Issues Collapsible -->
            <div class="bg-primary-light/50 border border-primary-border/70 rounded-2xl overflow-hidden">
                <button type="button" 
                        @click="showIssues = !showIssues"
                        class="w-full p-3 flex items-center justify-between text-xs font-bold text-primary-text text-left">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span>Connection Issues?</span>
                    </div>
                    <svg class="w-4 h-4 text-primary transition-transform duration-200" 
                         :class="{ 'rotate-180': showIssues }"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </button>
                <div x-show="showIssues" x-cloak class="px-3 pb-3 pt-1 text-xs text-gray-600 border-t border-primary-border/40 space-y-1">
                    <p>• Make sure Wi-Fi is toggled on.</p>
                    <p>• Disconnect and reconnect to the network if login page doesn't appear.</p>
                    <p>• Ensure your voucher has not expired or already been redeemed on another phone.</p>
                </div>
            </div>
            @endif

            <!-- Support Info -->
            <div class="pt-2 text-center text-xs text-gray-600">
                Need help? Call <span class="font-bold text-primary">{{ $tenant->settings['support_phone'] ?? '+256 791346328' }}</span>
            </div>
            
            <div class="text-center text-[11px] text-gray-400 pb-1">
                Powered by <span class="font-semibold text-gray-500">{{ config('app.name') }}</span> © {{ date('Y') }}
            </div>

        </div>
    </div>

</body>
</html>
