<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Vouchers
        </h2>
    </x-slot>

    <div class="py-8" x-data="{
        showOverlay: false,
        activeTab: 'all',
        form: { package_id: '', quantity: 50 },
        voucherPrefix: @js($voucherSettings['voucher_prefix']),
        voucherLength: @js($voucherSettings['voucher_length']),
        voucherFormat: @js($voucherSettings['voucher_format']),
        voucherFormatPreview() {
            const characters = this.voucherFormat === 'numeric'
                ? '0'.repeat(this.voucherLength)
                : 'X'.repeat(this.voucherLength);

            return this.voucherPrefix ? `${this.voucherPrefix}-${characters}` : characters;
        }
    }">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            {{-- Flash Message --}}
            @if(session('status'))
                <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg flex justify-between items-center">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-green-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                        <span class="text-sm font-medium">{{ session('status') }}</span>
                    </div>
                </div>
            @endif

            {{-- Page Header --}}
            <x-ui.page-header
                title="Vouchers"
                subtitle="Generate, distribute and track prepaid access without losing sight of your stock."
                class="mb-6"
            >
                <x-slot:actions>
                    <x-ui.button type="button" variant="secondary"
                        class="shadow-sm"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                        </svg>
                        Print vouchers
                    </x-ui.button>
                    <x-ui.button type="button" @click="showOverlay = true">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        Generate vouchers
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.page-header>

            {{-- KPI Cards --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                {{-- Generated This Month --}}
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 hover:shadow-md transition-shadow">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Generated this month</p>
                        <div class="w-8 h-8 rounded-lg bg-blue-50 flex items-center justify-center">
                            <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                            </svg>
                        </div>
                    </div>
                    <p class="text-3xl font-bold text-gray-900">{{ number_format($generatedThisMonth) }}</p>
                    @if($generatedThisMonth > 0)
                        <p class="text-xs text-gray-400 mt-1">Across all package types</p>
                    @else
                        <p class="text-xs text-gray-400 mt-1">No vouchers yet this month</p>
                    @endif
                </div>

                {{-- Unused Stock --}}
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 hover:shadow-md transition-shadow">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Unused stock</p>
                        <div class="w-8 h-8 rounded-lg bg-indigo-50 flex items-center justify-center">
                            <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 10V7"/>
                            </svg>
                        </div>
                    </div>
                    <p class="text-3xl font-bold text-gray-900">{{ number_format($unusedStock) }}</p>
                    @if($unusedFaceValueByCurrency->isEmpty())
                        <p class="text-xs text-gray-400 mt-1">Ready for distribution</p>
                    @else
                        <p class="text-xs text-gray-400 mt-1">
                            Face value:
                            {{ $unusedFaceValueByCurrency->map(fn ($row) => $row->currency.' '.number_format((float) $row->face_value))->join(', ') }}
                        </p>
                    @endif
                </div>

                {{-- Redeemed --}}
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 hover:shadow-md transition-shadow">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Redeemed</p>
                        <div class="w-8 h-8 rounded-lg bg-green-50 flex items-center justify-center">
                            <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                    </div>
                    <p class="text-3xl font-bold text-gray-900">{{ number_format($redeemed) }}</p>
                    @php $total = $generatedThisMonth > 0 ? round(($redeemed / max(1, $generatedThisMonth)) * 100) : 0; @endphp
                    <p class="text-xs text-gray-400 mt-1">{{ $total }}% redemption this month</p>
                </div>

                {{-- Voided --}}
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 hover:shadow-md transition-shadow">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Voided vouchers</p>
                        <div class="w-8 h-8 rounded-lg bg-gray-100 flex items-center justify-center">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                            </svg>
                        </div>
                    </div>
                    <p class="text-3xl font-bold text-gray-900">{{ number_format($voided) }}</p>
                    <p class="text-xs text-gray-400 mt-1">Excluded from available stock</p>
                </div>
            </div>

            {{-- Filter Tabs & Search Bar --}}
            <div class="bg-white rounded-t-xl border border-gray-200 shadow-sm">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 px-5 pt-4 pb-0">
                    {{-- Tabs --}}
                    <nav class="flex gap-1" role="tablist">
                        <button @click="activeTab = 'all'"
                            :class="activeTab === 'all' ? 'text-blue-600 border-b-2 border-blue-600' : 'text-gray-500 hover:text-gray-700 border-b-2 border-transparent'"
                            class="px-3 pb-3 text-sm font-medium transition-colors whitespace-nowrap">
                            All vouchers
                        </button>
                        <button @click="activeTab = 'unused'"
                            :class="activeTab === 'unused' ? 'text-blue-600 border-b-2 border-blue-600' : 'text-gray-500 hover:text-gray-700 border-b-2 border-transparent'"
                            class="px-3 pb-3 text-sm font-medium transition-colors whitespace-nowrap">
                            Unused <span class="ml-1 bg-blue-100 text-blue-700 text-xs px-1.5 py-0.5 rounded-full">{{ number_format($unusedStock) }}</span>
                        </button>
                        <button @click="activeTab = 'redeemed'"
                            :class="activeTab === 'redeemed' ? 'text-blue-600 border-b-2 border-blue-600' : 'text-gray-500 hover:text-gray-700 border-b-2 border-transparent'"
                            class="px-3 pb-3 text-sm font-medium transition-colors whitespace-nowrap">
                            Redeemed <span class="ml-1 bg-green-100 text-green-700 text-xs px-1.5 py-0.5 rounded-full">{{ number_format($redeemed) }}</span>
                        </button>
                        <button @click="activeTab = 'voided'"
                            :class="activeTab === 'voided' ? 'text-blue-600 border-b-2 border-blue-600' : 'text-gray-500 hover:text-gray-700 border-b-2 border-transparent'"
                            class="px-3 pb-3 text-sm font-medium transition-colors whitespace-nowrap">
                            Voided <span class="ml-1 bg-gray-100 text-gray-600 text-xs px-1.5 py-0.5 rounded-full">{{ number_format($voided) }}</span>
                        </button>
                    </nav>
                </div>

                {{-- Search & Filter Row --}}
                <div class="flex flex-col sm:flex-row items-center gap-3 px-5 py-3 border-t border-gray-100">
                    <div class="relative flex-1 w-full">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        <input type="text" placeholder="Search voucher code or batch..."
                            class="w-full pl-9 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 placeholder-gray-400">
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <select class="text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white">
                            <option>All packages</option>
                            @foreach($packages as $pkg)
                                <option>{{ $pkg->name }}</option>
                            @endforeach
                        </select>
                        <select class="text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white">
                            <option>All sites</option>
                        </select>
                        <button type="button" class="inline-flex items-center gap-1.5 text-sm border border-gray-300 rounded-lg px-3 py-2 bg-white text-gray-600 hover:bg-gray-50">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                            More filters
                        </button>
                    </div>
                </div>
            </div>

            {{-- Vouchers Table --}}
            <div class="bg-white border-x border-b border-gray-200 shadow-sm overflow-hidden mb-8 rounded-b-xl">
                <table class="min-w-full divide-y divide-gray-100">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="w-10 px-5 py-3">
                                <input type="checkbox" class="rounded border-gray-300 text-blue-600">
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                <div class="flex items-center gap-1">Voucher code <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path d="M5 12l5-5 5 5H5z"/></svg></div>
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Package / value</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Batch</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Assigned site</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Redeemed by</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($vouchers as $voucher)
                            <tr class="hover:bg-gray-50 transition-colors group">
                                <td class="px-5 py-4">
                                    <input type="checkbox" class="rounded border-gray-300 text-blue-600">
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <div class="font-mono text-sm font-semibold text-gray-900">{{ $voucher->code }}</div>
                                    <div class="text-xs text-gray-400 mt-0.5">Generated {{ $voucher->created_at->format('d M') }}</div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900 font-medium">{{ $voucher->package->name }}</div>
                                    <div class="text-xs text-gray-400 mt-0.5">{{ $voucher->package->currency }} {{ number_format($voucher->package->price) }}</div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-600 font-mono">
                                    {{ $voucher->batch->batch_code }}
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500">
                                    {{ $voucher->assigned_site ?? '—' }}
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500">
                                    {{ $voucher->redeemed_by ?? '—' }}
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    @if($voucher->status === 'unused')
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-blue-50 text-blue-700 border border-blue-100">
                                            <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span> Unused
                                        </span>
                                    @elseif($voucher->status === 'redeemed')
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-green-50 text-green-700 border border-green-100">
                                            <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span> Redeemed
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-600 border border-gray-200">
                                            <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span> Voided
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-right relative" x-data="{ open: false, copied: false }">
                                    <button @click="open = !open" type="button" class="text-gray-400 hover:text-gray-600 p-1.5 rounded-lg hover:bg-gray-100 transition-colors focus:outline-none">
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M6 10a2 2 0 11-4 0 2 2 0 014 0zM12 10a2 2 0 11-4 0 2 2 0 014 0zM16 12a2 2 0 100-4 2 2 0 000 4z"/></svg>
                                    </button>
                                    <div x-show="open" 
                                         @click.away="open = false" 
                                         x-transition:enter="transition ease-out duration-100"
                                         x-transition:enter-start="transform opacity-0 scale-95"
                                         x-transition:enter-end="transform opacity-100 scale-100"
                                         x-transition:leave="transition ease-in duration-75"
                                         x-transition:leave-start="transform opacity-100 scale-100"
                                         x-transition:leave-end="transform opacity-0 scale-95"
                                         class="absolute right-4 mt-1.5 w-44 bg-white rounded-xl shadow-lg border border-gray-100 py-1.5 z-50 text-left"
                                         x-cloak>
                                        <button type="button" 
                                                @click="navigator.clipboard.writeText('{{ $voucher->code }}'); copied = true; setTimeout(() => { copied = false; open = false; }, 800)" 
                                                class="w-full text-left px-3.5 py-2 text-xs font-medium text-gray-700 hover:bg-gray-50 flex items-center gap-2 transition-colors">
                                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"></path></svg>
                                            <span x-text="copied ? 'Copied code!' : 'Copy voucher code'"></span>
                                        </button>
                                        <button type="button" 
                                                @click="open = false; window.print()" 
                                                class="w-full text-left px-3.5 py-2 text-xs font-medium text-gray-700 hover:bg-gray-50 flex items-center gap-2 transition-colors">
                                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                                            Print voucher
                                        </button>
                                        @if(in_array($voucher->status, ['unused', 'redeemed'], true))
                                            <form method="POST" action="{{ route('operator.vouchers.void', ['tenant' => request()->route('tenant'), 'voucher' => $voucher->id]) }}"
                                                  data-confirm-action="Void this voucher? It can no longer be used, and a connected customer will be disconnected.">
                                                @csrf
                                                <button type="submit" class="w-full text-left px-3.5 py-2 text-xs font-medium text-red-600 hover:bg-red-50 flex items-center gap-2 transition-colors">
                                                    Void voucher
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-6 py-16 text-center">
                                    <div class="flex flex-col items-center gap-3">
                                        <div class="w-12 h-12 bg-gray-100 rounded-full flex items-center justify-center">
                                            <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"/>
                                            </svg>
                                        </div>
                                        <div>
                                            <p class="text-sm font-medium text-gray-900">No vouchers generated yet</p>
                                            <p class="text-xs text-gray-500 mt-1">Click <strong>Generate vouchers</strong> to create your first batch.</p>
                                        </div>
                                        <button type="button" @click="showOverlay = true" class="mt-1 text-sm text-blue-600 font-medium hover:underline">
                                            Generate your first batch →
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>

                {{-- Pagination --}}
                @if($vouchers->hasPages())
                    <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex items-center justify-between">
                        <p class="text-xs text-gray-500">Showing {{ $vouchers->firstItem() }}–{{ $vouchers->lastItem() }} of {{ number_format($vouchers->total()) }} records</p>
                        {{ $vouchers->links() }}
                    </div>
                @endif
            </div>

            {{-- Bottom Cards --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                {{-- Latest Batch Card --}}
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <div class="flex items-start justify-between mb-3">
                        <div>
                            <h4 class="text-sm font-bold text-gray-900">
                                Latest batch — {{ $latestBatch?->batch_code ?? 'None yet' }}
                            </h4>
                            @if($latestBatch)
                                <p class="text-xs text-gray-500 mt-1">
                                    {{ number_format($latestBatch->quantity) }} × {{ $latestBatch->package->name }}
                                    ({{ $latestBatch->package->currency }} {{ number_format($latestBatch->package->price * $latestBatch->quantity) }})
                                    · Created by {{ $latestBatch->creator?->name ?? 'System' }}
                                </p>
                            @else
                                <p class="text-xs text-gray-400 mt-1">No batches have been generated yet.</p>
                            @endif
                        </div>
                        @if($latestBatch)
                            <button type="button"
                                class="text-blue-600 text-xs font-semibold hover:underline whitespace-nowrap ml-4">
                                Download PDF
                            </button>
                        @endif
                    </div>
                    @if($latestBatch)
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-blue-50 text-blue-700 text-xs font-semibold border border-blue-100">
                            <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span> Ready to distribute
                        </span>
                    @endif
                </div>

                {{-- Redemption Rules Card --}}
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <div class="flex items-start justify-between mb-2">
                        <h4 class="text-sm font-bold text-gray-900">Redemption rules</h4>
                        <button type="button" class="text-blue-600 text-xs font-semibold hover:underline whitespace-nowrap ml-4">Edit policy</button>
                    </div>
                    <p class="text-xs text-gray-500 leading-relaxed">
                        Validity begins on first login, not when printed. Single-use codes expire unused after 90 days.
                        Voided codes cannot reconnect.
                    </p>
                    <div class="mt-3 pt-3 border-t border-gray-100">
                        <span class="inline-flex items-center gap-1.5 text-xs text-blue-600 font-medium">
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                            RADIUS validation enabled across all sites
                        </span>
                    </div>
                </div>
            </div>

        </div><!-- /max-w-7xl -->

        {{-- ═══════════════════════════════════════════════════════
             SIDE OVERLAY — Generate Vouchers
        ════════════════════════════════════════════════════════ --}}
        <div x-show="showOverlay" class="relative z-50" role="dialog" aria-modal="true" x-cloak>

            {{-- Backdrop --}}
            <div x-show="showOverlay"
                x-transition:enter="ease-in-out duration-300"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="ease-in-out duration-300"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="fixed inset-0 bg-gray-900/50 backdrop-blur-sm"
                @click="showOverlay = false">
            </div>

            {{-- Panel --}}
            <div class="fixed inset-0 overflow-hidden pointer-events-none">
                <div class="absolute inset-0 overflow-hidden">
                    <div class="pointer-events-none fixed inset-y-0 right-0 flex max-w-full pl-10">
                        <div x-show="showOverlay"
                            x-transition:enter="transform transition ease-in-out duration-500"
                            x-transition:enter-start="translate-x-full"
                            x-transition:enter-end="translate-x-0"
                            x-transition:leave="transform transition ease-in-out duration-500"
                            x-transition:leave-start="translate-x-0"
                            x-transition:leave-end="translate-x-full"
                            class="pointer-events-auto w-screen max-w-md">

                            <div class="flex h-full flex-col bg-white shadow-2xl">

                                {{-- Drawer Header --}}
                                <div class="px-6 py-5 border-b border-gray-200 bg-gray-50">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <h2 class="text-base font-semibold text-gray-900">Generate Vouchers</h2>
                                            <p class="text-xs text-gray-500 mt-0.5">Create a new batch of prepaid access codes.</p>
                                        </div>
                                        <button @click="showOverlay = false"
                                            class="rounded-lg p-1.5 text-gray-400 hover:text-gray-600 hover:bg-gray-200 transition-colors">
                                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                            </svg>
                                        </button>
                                    </div>
                                </div>

                                {{-- Drawer Body --}}
                                <div class="flex-1 overflow-y-auto px-6 py-6">
                                    <form id="generate-vouchers-form"
                                        action="{{ route('operator.vouchers.store') }}"
                                        method="POST">
                                        @csrf

                                        <div class="space-y-5">

                                            {{-- Package Select --}}
                                            <div>
                                                <label for="overlay-package" class="block text-sm font-medium text-gray-700 mb-1">
                                                    Package <span class="text-red-500">*</span>
                                                </label>
                                                @if($packages->isEmpty())
                                                    <div class="flex items-center gap-2 text-amber-600 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 text-sm">
                                                        <svg class="w-4 h-4 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                                                        No published packages yet. <a href="{{ route('operator.packages.index') }}" class="underline font-medium">Create one first.</a>
                                                    </div>
                                                @else
                                                    <select id="overlay-package" name="package_id" required
                                                        x-model="form.package_id"
                                                        class="block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                                        <option value="">Select a package…</option>
                                                        @foreach($packages as $pkg)
                                                            <option value="{{ $pkg->id }}">
                                                                {{ $pkg->name }} — {{ $pkg->currency }} {{ number_format($pkg->price) }}
                                                                / {{ $pkg->duration_value }} {{ $pkg->duration_unit }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                @endif
                                            </div>

                                            {{-- Quantity --}}
                                            <div>
                                                <label for="overlay-quantity" class="block text-sm font-medium text-gray-700 mb-1">
                                                    Quantity to generate <span class="text-red-500">*</span>
                                                </label>
                                                <input id="overlay-quantity" type="number" name="quantity"
                                                    x-model="form.quantity"
                                                    min="1" max="1000" required
                                                    class="block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                                <p class="text-xs text-gray-400 mt-1">Maximum 1,000 codes per batch.</p>
                                            </div>

                                            {{-- Live preview --}}
                                            <div class="rounded-lg bg-blue-50 border border-blue-100 px-4 py-3" x-show="form.package_id && form.quantity > 0">
                                                <p class="text-xs font-semibold text-blue-700 uppercase tracking-wide mb-1.5">Batch preview</p>
                                                <div class="space-y-1">
                                                    <div class="flex justify-between text-xs text-blue-800">
                                                        <span>Voucher codes</span>
                                                        <span x-text="parseInt(form.quantity || 0).toLocaleString()"></span>
                                                    </div>
                                                    <div class="flex justify-between text-xs text-blue-800">
                                                        <span>Format</span>
                                                        <span class="font-mono" x-text="voucherFormatPreview()"></span>
                                                    </div>
                                                </div>
                                            </div>

                                        </div>
                                    </form>
                                </div>

                                {{-- Drawer Footer --}}
                                <div class="px-6 py-4 border-t border-gray-200 bg-gray-50">
                                    <div class="flex items-center gap-3">
                                        <button type="button" @click="showOverlay = false"
                                            class="flex-1 bg-white border border-gray-300 text-gray-700 py-2.5 rounded-lg text-sm font-medium hover:bg-gray-50 transition-colors">
                                            Cancel
                                        </button>
                                        <button type="submit" form="generate-vouchers-form"
                                            class="flex-1 bg-blue-600 text-white py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 shadow-sm transition-colors flex items-center justify-center gap-2">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                            </svg>
                                            Generate Batch
                                        </button>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        {{-- ═══ END SIDE OVERLAY ═══ --}}

    </div>
</x-app-layout>
