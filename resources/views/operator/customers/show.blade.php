<div class="p-6">
    <!-- Drawer Header -->
    <div class="flex justify-between items-center pb-5 mb-5 border-b border-gray-100">
        <div>
            <h2 class="text-lg font-bold text-gray-900">View customer</h2>
            <p class="text-xs text-gray-500 mt-0.5">Comprehensive profile, connection, and package subscription overview.</p>
        </div>
        <button @click="showViewOverlay = false" type="button" class="text-gray-400 hover:text-gray-600 p-1.5 rounded-lg hover:bg-gray-100 transition-colors">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
        </button>
    </div>

    <!-- Customer Information Card -->
    <div class="border border-gray-200/90 rounded-2xl p-5 mb-4 bg-white shadow-xs">
        <h3 class="text-xs font-bold text-gray-800 uppercase tracking-wider mb-4 flex items-center gap-2">
            <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            Customer Information
        </h3>
        
        <div class="grid grid-cols-3 gap-4 mb-4">
            <div>
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider mb-1">Customer ID</p>
                <span class="inline-block bg-amber-50 text-amber-700 border border-amber-200/70 px-2.5 py-0.5 rounded-md text-xs font-mono font-bold">
                    CUS-{{ str_pad($customer->id, 4, '0', STR_PAD_LEFT) }}
                </span>
            </div>
            <div>
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider mb-1">Voucher</p>
                <span class="inline-block text-gray-500 text-xs font-mono bg-gray-50 border border-gray-200/70 px-2 py-0.5 rounded-md">
                    N/A
                </span>
            </div>
            <div>
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider mb-1">Status</p>
                @if(($customer->status ?? 'active') === 'active')
                    <span class="inline-flex items-center gap-1 bg-emerald-50 text-emerald-700 border border-emerald-200/70 px-2.5 py-0.5 rounded-md text-xs font-bold">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Active
                    </span>
                @else
                    <span class="inline-flex items-center gap-1 bg-gray-100 text-gray-600 border border-gray-200 px-2.5 py-0.5 rounded-md text-xs font-bold">
                        <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span> Inactive
                    </span>
                @endif
            </div>
        </div>

        <div class="grid grid-cols-3 gap-4 pt-3 border-t border-gray-100">
            <div>
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider mb-1">Full Name</p>
                <span class="inline-block bg-emerald-50/70 text-emerald-800 border border-emerald-200/60 px-2.5 py-0.5 rounded-md text-xs font-semibold">
                    {{ $customer->name ?? 'Guest' }}
                </span>
            </div>
            <div>
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider mb-1">Phone</p>
                <span class="inline-flex items-center gap-1 text-gray-700 text-xs bg-gray-50 border border-gray-200/70 px-2 py-0.5 rounded-md">
                    <svg class="w-3 h-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                    {{ $customer->phone ?: 'Walk-in' }}
                </span>
            </div>
            <div>
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider mb-1">Service Type</p>
                <span class="inline-block bg-blue-50 text-blue-700 border border-blue-200/70 px-2.5 py-0.5 rounded-md text-xs font-mono font-bold uppercase">
                    HOTSPOT
                </span>
            </div>
        </div>
    </div>

    <!-- Package & Subscription Card -->
    <div class="border border-gray-200/90 rounded-2xl p-5 mb-4 bg-white shadow-xs">
        <h3 class="text-xs font-bold text-gray-800 uppercase tracking-wider mb-4 flex items-center gap-2">
            <svg class="w-4 h-4 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Package & Subscription
        </h3>
        
        <div class="grid grid-cols-3 gap-4 mb-4">
            <div>
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider mb-1">Package</p>
                @if($customer->package)
                    <span class="inline-block bg-blue-50 text-blue-700 border border-blue-200/80 px-2.5 py-0.5 rounded-md text-xs font-semibold">
                        {{ $customer->package->name }}
                    </span>
                @else
                    <span class="inline-block bg-gray-100 text-gray-500 border border-gray-200 px-2 py-0.5 rounded-md text-xs">
                        No package
                    </span>
                @endif
            </div>
            <div>
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider mb-1">Expiry</p>
                @if($customer->package)
                    @php
                        $duration = $customer->package->duration_value ?? 30;
                        $unit = $customer->package->duration_unit ?? 'days';
                        $expiryDate = $unit === 'months' ? $customer->created_at->addMonths($duration) : ($unit === 'hours' ? $customer->created_at->addHours($duration) : $customer->created_at->addDays($duration));
                    @endphp
                    <span class="inline-block bg-emerald-50 text-emerald-700 border border-emerald-200/70 px-2 py-0.5 rounded-md text-xs font-mono">
                        {{ $expiryDate->format('d M Y, H:i') }}
                    </span>
                @else
                    <span class="text-gray-400 text-xs">N/A</span>
                @endif
            </div>
            <div>
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider mb-1">Registered</p>
                <span class="inline-block bg-gray-50 text-gray-600 border border-gray-200/70 px-2 py-0.5 rounded-md text-xs font-mono">
                    {{ $customer->created_at->format('d M Y, H:i') }}
                </span>
            </div>
        </div>

        <div class="grid grid-cols-3 gap-4 pt-3 border-t border-gray-100">
            <div>
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider mb-1">MAC Address</p>
                <span class="inline-block font-mono text-xs {{ $customer->mac_address ? 'text-gray-700' : 'text-gray-400' }}">
                    {{ $customer->mac_address ?: 'N/A' }}
                </span>
            </div>
            <div>
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider mb-1">IP Address</p>
                <span class="text-gray-400 text-xs font-mono">DHCP Assigned</span>
            </div>
            <div>
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider mb-1">Email</p>
                @if($customer->email)
                    <span class="inline-block text-blue-600 text-xs font-medium bg-blue-50/50 border border-blue-200/60 px-2 py-0.5 rounded truncate max-w-full" title="{{ $customer->email }}">
                        {{ $customer->email }}
                    </span>
                @else
                    <span class="text-gray-400 text-xs">N/A</span>
                @endif
            </div>
        </div>
    </div>

    <!-- Data Usage Card -->
    <div class="border border-gray-200/90 rounded-2xl p-5 mb-4 bg-white shadow-xs" x-data="{ open: true }">
        <button @click="open = !open" type="button" class="w-full flex justify-between items-center focus:outline-none">
            <h3 class="text-xs font-bold text-gray-800 uppercase tracking-wider flex items-center gap-2">
                <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                Data Usage
            </h3>
            <svg class="w-4 h-4 text-gray-400 transform transition-transform" :class="{'rotate-180': open}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
        </button>
        
        <div x-show="open" class="grid grid-cols-3 gap-4 mt-4 pt-3 border-t border-gray-100">
            <div>
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider mb-1">Total Data Used</p>
                <span class="inline-flex items-center gap-1 bg-blue-50 text-blue-700 border border-blue-200/80 px-2.5 py-0.5 rounded-md text-xs font-mono font-bold">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    0 B
                </span>
            </div>
            <div>
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider mb-1">Downloaded</p>
                <span class="inline-flex items-center gap-1 bg-emerald-50 text-emerald-700 border border-emerald-200/80 px-2.5 py-0.5 rounded-md text-xs font-mono font-bold">
                    &darr; 0 B
                </span>
            </div>
            <div>
                <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider mb-1">Uploaded</p>
                <span class="inline-flex items-center gap-1 bg-amber-50 text-amber-700 border border-amber-200/80 px-2.5 py-0.5 rounded-md text-xs font-mono font-bold">
                    &uarr; 0 B
                </span>
            </div>
        </div>
    </div>

    <!-- Additional Details Card -->
    <div class="border border-gray-200/90 rounded-2xl p-5 mb-6 bg-white shadow-xs" x-data="{ open: false }">
        <button @click="open = !open" type="button" class="w-full flex justify-between items-center focus:outline-none">
            <h3 class="text-xs font-bold text-gray-800 uppercase tracking-wider flex items-center gap-2">
                <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Additional Details
            </h3>
            <svg class="w-4 h-4 text-gray-400 transform transition-transform" :class="{'rotate-180': open}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
        </button>
        <div x-show="open" class="mt-4 pt-3 border-t border-gray-100 text-xs text-gray-500 space-y-2">
            <div class="flex justify-between">
                <span>Account Created:</span>
                <span class="font-mono text-gray-700">{{ $customer->created_at->format('Y-m-d H:i:s') }}</span>
            </div>
            <div class="flex justify-between">
                <span>Last Updated:</span>
                <span class="font-mono text-gray-700">{{ $customer->updated_at->format('Y-m-d H:i:s') }}</span>
            </div>
            <div class="flex justify-between">
                <span>Tenant ID:</span>
                <span class="font-mono text-gray-700">{{ $customer->tenant_id }}</span>
            </div>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="flex items-center justify-between pt-2">
        <button @click="showViewOverlay = false" type="button" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-5 py-2.5 rounded-xl text-xs font-semibold transition-colors">
            Close
        </button>
        <button type="button" @click="showViewOverlay = false; openEdit({{ json_encode($customer) }})" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-xl text-xs font-semibold shadow-sm transition-colors flex items-center gap-1.5">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
            Edit customer
        </button>
    </div>
</div>
