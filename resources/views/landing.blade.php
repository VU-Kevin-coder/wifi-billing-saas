<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hamnet Wi-Fi Billing System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-gray-50 text-gray-900">

    <!-- Navbar -->
    <nav class="bg-white border-b border-gray-100 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex justify-between items-center h-16">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"></path></svg>
                </div>
                <span class="text-xl font-bold text-gray-900">Hamnet</span>
            </div>
            <div class="flex items-center gap-4">
                <a href="{{ route('login') }}" class="text-sm font-medium text-gray-600 hover:text-gray-900">Log in</a>
                <a href="{{ route('register') }}" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2 rounded-lg shadow-sm transition-colors">Get Started Free</a>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="py-20 px-4">
        <div class="max-w-4xl mx-auto text-center">
            <div class="inline-block bg-blue-50 text-blue-700 text-xs font-bold px-3 py-1 rounded-full mb-4 border border-blue-100">
                🚀 The Modern ISP Billing Platform
            </div>
            <h1 class="text-5xl md:text-6xl font-extrabold text-gray-900 leading-tight mb-6">
                Manage Your Wi-Fi Business <span class="text-blue-600">From One Dashboard</span>
            </h1>
            <p class="text-lg text-gray-500 mb-8 max-w-2xl mx-auto">
                Hamnet helps hotspot operators, ISPs, and Wi-Fi businesses sell vouchers, manage MikroTik routers, track live sessions, and accept mobile money payments — all in one place.
            </p>
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="{{ route('register') }}" class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-8 py-3 rounded-xl shadow-lg shadow-blue-200 transition-all hover:scale-105">
                    Start Your Free Trial
                </a>
                <a href="{{ route('login') }}" class="bg-white hover:bg-gray-50 text-gray-700 font-bold px-8 py-3 rounded-xl border border-gray-200 shadow-sm transition-all">
                    Operator Login
                </a>
            </div>
            <p class="text-xs text-gray-400 mt-4">No credit card required. Cancel anytime.</p>
        </div>
    </section>

    <!-- Features Grid -->
    <section class="py-16 px-4 bg-white">
        <div class="max-w-7xl mx-auto">
            <div class="text-center mb-12">
                <h2 class="text-3xl font-bold text-gray-900 mb-2">Everything You Need to Run Your Network</h2>
                <p class="text-gray-500">Built for the realities of African ISPs.</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <!-- Feature 1: Vouchers -->
                <div class="p-6 rounded-2xl bg-gray-50 border border-gray-100">
                    <div class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"></path></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Voucher Management</h3>
                    <p class="text-sm text-gray-500">Generate, track, and redeem vouchers instantly. Set custom prefixes, lengths, and expiry rules.</p>
                </div>
                <!-- Feature 2: MikroTik -->
                <div class="p-6 rounded-2xl bg-gray-50 border border-gray-100">
                    <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"></path></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">MikroTik Integration</h3>
                    <p class="text-sm text-gray-500">Connect your routers, monitor CPU/RAM, and push voucher codes directly to the hotspot.</p>
                </div>
                <!-- Feature 3: Mobile Money -->
                <div class="p-6 rounded-2xl bg-gray-50 border border-gray-100">
                    <div class="w-12 h-12 bg-purple-100 rounded-xl flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Mobile Money Ready</h3>
                    <p class="text-sm text-gray-500">Accept MTN and Airtel mobile money payments through Flutterwave from your captive portal.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Social Proof / Stats Bar -->
    <section class="py-12 px-4 bg-gray-50">
        <div class="max-w-4xl mx-auto">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-8 text-center">
                <div>
                    <p class="text-4xl font-extrabold text-blue-600">Multi-tenant</p>
                    <p class="text-sm text-gray-500 mt-1">Isolated operator workspaces</p>
                </div>
                <div>
                    <p class="text-4xl font-extrabold text-blue-600">Real-time</p>
                    <p class="text-sm text-gray-500 mt-1">Live session monitoring</p>
                </div>
                <div>
                    <p class="text-4xl font-extrabold text-blue-600">Role-based</p>
                    <p class="text-sm text-gray-500 mt-1">Admin, Finance, Support & Network roles</p>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="py-20 px-4 bg-blue-600">
        <div class="max-w-3xl mx-auto text-center">
            <h2 class="text-3xl md:text-4xl font-extrabold text-white mb-4">Ready to modernise your Wi-Fi business?</h2>
            <p class="text-blue-100 mb-8">Join operators already managing their networks with Hamnet. Free to start, no contracts.</p>
            <a href="{{ route('register') }}" class="inline-block bg-white hover:bg-gray-100 text-blue-600 font-bold px-10 py-4 rounded-xl shadow-xl transition-all hover:scale-105">
                Create Your Free Account
            </a>
        </div>
    </section>

    <!-- Footer -->
    <footer class="py-12 bg-gray-900 text-center">
        <div class="flex items-center justify-center gap-2 mb-4">
            <div class="w-6 h-6 bg-blue-500 rounded flex items-center justify-center">
                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01"></path></svg>
            </div>
            <span class="text-white font-bold">Hamnet Wi-Fi Billing System</span>
        </div>
        <p class="text-sm text-gray-400">© {{ date('Y') }} Hamnet. All rights reserved.</p>
    </footer>

</body>
</html>
