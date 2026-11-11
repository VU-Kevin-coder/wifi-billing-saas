<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Customers
        </h2>
    </x-slot>

    <div class="py-8" x-data="{ 
        showOverlay: false,
        showViewOverlay: false,
        loadingView: false,
        customerHtml: '',
        editMode: false,
        editId: null,
        form: {
            name: '',
            phone: '',
            email: '',
            package_id: '',
            mac_address: '',
            status: 'active'
        },
        openCreate() {
            this.editMode = false;
            this.editId = null;
            this.form = { name: '', phone: '', email: '', package_id: '', mac_address: '', status: 'active' };
            this.showOverlay = true;
        },
        openEdit(cust) {
            this.editMode = true;
            this.editId = cust.id;
            this.form = { 
                name: cust.name || '', 
                phone: cust.phone || '',
                email: cust.email || '',
                package_id: cust.package_id || '',
                mac_address: cust.mac_address || '',
                status: cust.status || 'active'
            };
            this.showOverlay = true;
        },
        viewCustomer(id) {
            this.loadingView = true;
            this.customerHtml = '';
            this.showViewOverlay = true;
            window.apiFetch('{{ url('/operator/customers') }}/' + id, {
                responseType: 'text',
            })
            .then(html => {
                this.customerHtml = html;
                this.loadingView = false;
            })
            .catch(() => {
                this.customerHtml = '<div class=\'p-6 text-center text-red-500\'>Failed to load customer details.</div>';
                this.loadingView = false;
            });
        }
    }"
        @scroll.window="open = false">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            <!-- Flash Message -->
            @if(session('status'))
                <div class="mb-6 bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 rounded-xl flex justify-between items-center text-sm shadow-sm">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span>{{ session('status') }}</span>
                    </div>
                    <button type="button" @click="$el.parentElement.remove()" class="text-emerald-500 hover:text-emerald-700">&times;</button>
                </div>
            @endif
            
            <!-- Page Title & Buttons -->
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Customers</h1>
                    <p class="text-gray-500 text-sm mt-1">Know every connection. Manage customer accounts, plan subscriptions and service history.</p>
                </div>
                <div class="flex gap-2">
                    <a href="{{ route('operator.customers.export', request()->only(['q', 'status'])) }}" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium shadow-sm hover:bg-gray-50 flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                        Export customers
                    </a>
                    <button @click="openCreate()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium shadow-sm flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                        Add customer
                    </button>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <p class="text-sm font-medium text-gray-500">Total customers</p>
                    <p class="text-3xl font-bold text-gray-900 mt-2">{{ number_format($totalCustomers) }}</p>
                </div>
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <p class="text-sm font-medium text-gray-500">With active service</p>
                    <p class="text-3xl font-bold text-gray-900 mt-2">{{ number_format($activeCustomers) }}</p>
                </div>
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <p class="text-sm font-medium text-gray-500">Outstanding balance</p>
                    <p class="text-3xl font-bold text-gray-900 mt-2">{{ $currency }} {{ number_format($outstandingBalance) }}</p>
                </div>
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <p class="text-sm font-medium text-gray-500">New this month</p>
                    <p class="text-3xl font-bold text-gray-900 mt-2">{{ number_format($newThisMonth) }}</p>
                </div>
            </div>

            <!-- Search & Filters Bar -->
            <form method="GET" action="{{ route('operator.customers.index') }}" class="bg-white p-4 rounded-t-xl border border-gray-200 border-b-0 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-col gap-3 sm:flex-row">
                    <div class="relative w-full sm:w-72">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                        </div>
                        <input type="search" name="q" value="{{ $validated['q'] ?? '' }}" placeholder="Search name, phone, email or MAC"
                               class="block w-full pl-10 pr-3 py-2 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-blue-500 bg-gray-50">
                    </div>
                    <select name="status" class="w-full sm:w-40 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">All statuses</option>
                        <option value="active" @selected(($validated['status'] ?? '') === 'active')>Active</option>
                        <option value="inactive" @selected(($validated['status'] ?? '') === 'inactive')>Inactive</option>
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    @if(($validated['q'] ?? '') !== '' || ($validated['status'] ?? '') !== '')
                        <a href="{{ route('operator.customers.index') }}" class="px-3 py-2 text-sm font-medium text-gray-600 hover:text-gray-900">Clear</a>
                    @endif
                    <button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700">Apply filters</button>
                </div>
            </form>

            <!-- Main Table -->
            <div class="bg-white rounded-b-xl shadow-sm border border-gray-200">
                <div class="overflow-x-auto min-h-[250px]">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-white">
                            <tr>
                                <th class="px-4 py-3 text-left">
                                    <input type="checkbox" class="rounded border-gray-300 text-blue-600 shadow-sm">
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-900 uppercase">Customer</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-900 uppercase">Phone / Email</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-900 uppercase">Package</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-900 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-900 uppercase">Created</th>
                                <th class="relative px-4 py-3 text-right text-xs font-bold text-gray-900 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            @forelse($customers as $customer)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <input type="checkbox" class="rounded border-gray-300 text-blue-600 shadow-sm">
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <button type="button" @click="viewCustomer({{ $customer->id }})" class="text-sm font-bold text-orange-600 bg-orange-50 hover:bg-orange-100 transition-colors inline-block px-2.5 py-0.5 rounded cursor-pointer">
                                            {{ $customer->name ?? 'Guest' }}
                                        </button>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-600">
                                        <div>{{ $customer->phone ?: '—' }}</div>
                                        @if($customer->email)
                                            <div class="text-[11px] text-gray-400 mt-0.5">{{ $customer->email }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm">
                                        @if($customer->package)
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium bg-blue-50 text-blue-700 border border-blue-200">
                                                {{ $customer->package->name }}
                                            </span>
                                        @else
                                            <span class="text-gray-400 text-xs">No package</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        @if(($customer->status ?? 'active') === 'active')
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200/80 text-xs font-bold">
                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Active
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-gray-100 text-gray-600 border border-gray-200 text-xs font-bold">
                                                <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span> Inactive
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ $customer->created_at->format('d M Y, H:i') }}
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <!-- Actions Dropdown -->
                                        <div class="relative inline-block text-left" x-data="{
                                            open: false,
                                            menuPosition: {},
                                            toggleMenu(event) {
                                                this.open = !this.open;
                                                if (!this.open) return;
                                                const rect = event.currentTarget.getBoundingClientRect();
                                                const menuHeight = 150;
                                                const top = rect.bottom + menuHeight > window.innerHeight
                                                    ? Math.max(8, rect.top - menuHeight - 6)
                                                    : rect.bottom + 6;
                                                this.menuPosition = { top: `${top}px`, right: `${Math.max(8, window.innerWidth - rect.right)}px` };
                                            }
                                        }">
                                            <button @click="toggleMenu($event)"
                                                    type="button" 
                                                    aria-label="Customer actions"
                                                    :aria-expanded="open"
                                                    class="p-1.5 text-gray-400 hover:text-gray-600 rounded-lg hover:bg-gray-100 transition-colors focus:outline-none">
                                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"></path></svg>
                                            </button>

                                            <!-- Dropdown Menu -->
                                            <div x-show="open"
                                                 @click.outside="open = false"
                                                 x-transition:enter="transition ease-out duration-100"
                                                 x-transition:enter-start="transform opacity-0 scale-95"
                                                 x-transition:enter-end="transform opacity-100 scale-100"
                                                 x-transition:leave="transition ease-in duration-75"
                                                 x-transition:leave-start="transform opacity-100 scale-100"
                                                 x-transition:leave-end="transform opacity-0 scale-95"
                                                 :style="menuPosition"
                                                 class="fixed w-44 bg-white rounded-xl shadow-xl border border-gray-200 py-1.5 z-[100] text-left"
                                                 x-cloak>
                                                
                                                <button type="button" 
                                                        @click="open = false; viewCustomer({{ $customer->id }})" 
                                                        class="w-full text-left px-3.5 py-2 text-xs font-medium text-gray-700 hover:bg-gray-50 flex items-center gap-2 transition-colors">
                                                    <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                                    View customer
                                                </button>

                                                <button type="button" 
                                                        @click="open = false; openEdit({{ json_encode($customer) }})" 
                                                        class="w-full text-left px-3.5 py-2 text-xs font-medium text-gray-700 hover:bg-gray-50 flex items-center gap-2 transition-colors">
                                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                                                    Edit customer
                                                </button>

                                                <div class="border-t border-gray-100 my-1"></div>

                                                <form action="{{ route('operator.customers.destroy', $customer) }}" method="POST" data-confirm-action="Are you sure you want to delete customer {{ addslashes($customer->name) }}?">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" 
                                                            class="w-full text-left px-3.5 py-2 text-xs font-medium text-red-600 hover:bg-red-50 flex items-center gap-2 transition-colors">
                                                        <svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                                        Delete customer
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-12 text-center text-sm">
                                        <p class="font-semibold text-gray-700">{{ ($validated['q'] ?? '') !== '' || ($validated['status'] ?? '') !== '' ? 'No customers match these filters.' : 'No customers yet.' }}</p>
                                        @if(($validated['q'] ?? '') !== '' || ($validated['status'] ?? '') !== '')
                                            <a href="{{ route('operator.customers.index') }}" class="mt-2 inline-flex text-blue-600 hover:text-blue-800">Clear filters</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                
                <!-- Footer -->
                <div class="px-4 py-3 border-t border-gray-200 flex flex-col gap-3 sm:flex-row sm:justify-between sm:items-center bg-white">
                    <span class="text-sm text-gray-500">Showing {{ $customers->firstItem() ?? 0 }} to {{ $customers->lastItem() ?? 0 }} of {{ $customers->total() }} results</span>
                    {{ $customers->links() }}
                </div>
            </div>

        </div>

        <!-- ─── SLIDE-OVER OVERLAY (CREATE / EDIT CUSTOMER) ─── -->
        <div x-show="showOverlay" class="relative z-50" aria-labelledby="slide-over-title" role="dialog" aria-modal="true" x-cloak>
            
            <!-- Backdrop -->
            <div x-show="showOverlay" 
                 x-transition:enter="ease-in-out duration-300" 
                 x-transition:enter-start="opacity-0" 
                 x-transition:enter-end="opacity-100" 
                 x-transition:leave="ease-in-out duration-300" 
                 x-transition:leave-start="opacity-100" 
                 x-transition:leave-end="opacity-0" 
                 class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity" 
                 @click="showOverlay = false"></div>

            <div class="fixed inset-0 overflow-hidden">
                <div class="absolute inset-0 overflow-hidden">
                    <div class="pointer-events-none fixed inset-y-0 right-0 flex max-w-full pl-10">
                        
                        <!-- Sliding Panel -->
                        <div x-show="showOverlay" 
                             x-transition:enter="transform transition ease-in-out duration-300 sm:duration-400" 
                             x-transition:enter-start="translate-x-full" 
                             x-transition:enter-end="translate-x-0" 
                             x-transition:leave="transform transition ease-in-out duration-300 sm:duration-400" 
                             x-transition:leave-start="translate-x-0" 
                             x-transition:leave-end="translate-x-full" 
                             class="pointer-events-auto w-screen max-w-md">
                            
                            <div class="flex h-full flex-col overflow-y-scroll bg-white shadow-2xl">
                                
                                <!-- Drawer Header -->
                                <div class="px-6 py-5 bg-slate-50 border-b border-slate-200">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <h2 class="text-base font-bold text-slate-900" id="slide-over-title" x-text="editMode ? 'Edit customer' : 'Add new customer'"></h2>
                                            <p class="text-xs text-slate-500 mt-0.5" x-text="editMode ? 'Update customer profile, plan assignment and connection details.' : 'Register a customer and assign an internet package plan.'"></p>
                                        </div>
                                        <button @click="showOverlay = false" type="button" class="text-slate-400 hover:text-slate-600 rounded-lg p-1.5 hover:bg-slate-100 transition-colors">
                                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>

                                <!-- Drawer Form -->
                                <div class="relative flex-1 px-6 py-6">
                                    <form :action="editMode ? '{{ url('operator/customers') }}/' + editId : '{{ route('operator.customers.store') }}'" method="POST">
                                        @csrf
                                        <template x-if="editMode">
                                            <input type="hidden" name="_method" value="PUT">
                                        </template>

                                        <div class="space-y-4 text-xs">
                                            <!-- Name -->
                                            <div>
                                                <label for="customer_name" class="block font-medium text-slate-700 mb-1">Full Name <span class="text-red-400">*</span></label>
                                                <input type="text" id="customer_name" name="name" x-model="form.name" placeholder="e.g. Amina Namusoke" required 
                                                       class="block w-full rounded-lg border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                @error('name')
                                                    <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                                @enderror
                                            </div>

                                            <!-- Phone -->
                                            <div>
                                                <label for="customer_phone" class="block font-medium text-slate-700 mb-1">Phone Number</label>
                                                <input type="text" id="customer_phone" name="phone" x-model="form.phone" placeholder="e.g. +256 772 410 682" 
                                                       class="block w-full rounded-lg border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                @error('phone')
                                                    <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                                @enderror
                                            </div>

                                            <!-- Email -->
                                            <div>
                                                <label for="customer_email" class="block font-medium text-slate-700 mb-1">Email Address</label>
                                                <input type="email" id="customer_email" name="email" x-model="form.email" placeholder="e.g. customer@example.com" 
                                                       class="block w-full rounded-lg border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                @error('email')
                                                    <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                                @enderror
                                            </div>

                                            <!-- Assign Package Plan -->
                                            <div>
                                                <label for="customer_package" class="block font-medium text-slate-700 mb-1">Assign Internet Package</label>
                                                <select id="customer_package" name="package_id" x-model="form.package_id"
                                                        class="block w-full rounded-lg border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                    <option value="">No Package / Unassigned</option>
                                                    @foreach($packages as $pkg)
                                                        <option value="{{ $pkg->id }}">
                                                            {{ $pkg->name }} — {{ $pkg->currency }} {{ number_format($pkg->price) }} ({{ $pkg->duration_value }} {{ $pkg->duration_unit }})
                                                        </option>
                                                    @endforeach
                                                </select>
                                                <p class="text-[11px] text-slate-400 mt-1">Assigning a package establishes their hotspot plan and duration validity.</p>
                                            </div>

                                            <!-- MAC Address -->
                                            <div>
                                                <label for="customer_mac" class="block font-medium text-slate-700 mb-1">Device MAC Address (Optional)</label>
                                                <input type="text" id="customer_mac" name="mac_address" x-model="form.mac_address" placeholder="e.g. AA:BB:CC:DD:EE:FF" 
                                                       class="block w-full font-mono rounded-lg border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                            </div>

                                            <!-- Status -->
                                            <div>
                                                <label for="customer_status" class="block font-medium text-slate-700 mb-1">Account Status</label>
                                                <select id="customer_status" name="status" x-model="form.status"
                                                        class="block w-full rounded-lg border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                    <option value="active">Active (Permitted)</option>
                                                    <option value="inactive">Inactive / Suspended</option>
                                                </select>
                                            </div>
                                        </div>

                                        <!-- Info Banner -->
                                        <div class="mt-6 bg-blue-50/70 border border-blue-200/80 rounded-xl p-3.5 flex items-start gap-3">
                                            <div class="w-5 h-5 rounded-full border border-blue-400 text-blue-600 flex items-center justify-center font-bold text-[10px] shrink-0 mt-0.5">i</div>
                                            <div>
                                                <p class="text-xs text-blue-700/80">
                                                    Package assignment and account parameters are saved directly to the customer's profile.
                                                </p>
                                            </div>
                                        </div>

                                        <div class="mt-8 pt-4 border-t border-slate-200 flex gap-2.5">
                                            <button type="button" @click="showOverlay = false" 
                                                    class="flex-1 bg-white border border-slate-200 text-slate-700 py-2.5 rounded-lg text-xs font-semibold hover:bg-slate-50 transition-colors shadow-sm">
                                                Cancel
                                            </button>
                                            <button type="submit" 
                                                    class="flex-1 bg-blue-600 text-white py-2.5 rounded-lg text-xs font-semibold hover:bg-blue-700 shadow-sm shadow-blue-500/20 transition-colors"
                                                    x-text="editMode ? 'Save Changes' : 'Add Customer & Assign Plan'">
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- ─── END SLIDE-OVER OVERLAY ─── -->

        <!-- ─── SIDE-OVERLAY (VIEW CUSTOMER) ─── -->
        <div x-show="showViewOverlay" class="relative z-50" aria-labelledby="view-overlay-title" role="dialog" aria-modal="true" x-cloak>
            
            <!-- Backdrop -->
            <div x-show="showViewOverlay" 
                 x-transition:enter="ease-in-out duration-300" 
                 x-transition:enter-start="opacity-0" 
                 x-transition:enter-end="opacity-100" 
                 x-transition:leave="ease-in-out duration-300" 
                 x-transition:leave-start="opacity-100" 
                 x-transition:leave-end="opacity-0" 
                 class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity" 
                 @click="showViewOverlay = false"></div>

            <div class="fixed inset-0 overflow-hidden">
                <div class="absolute inset-0 overflow-hidden">
                    <div class="pointer-events-none fixed inset-y-0 right-0 flex max-w-full pl-10">
                        
                        <!-- Sliding Panel -->
                        <div x-show="showViewOverlay" 
                             x-transition:enter="transform transition ease-in-out duration-300 sm:duration-400" 
                             x-transition:enter-start="translate-x-full" 
                             x-transition:enter-end="translate-x-0" 
                             x-transition:leave="transform transition ease-in-out duration-300 sm:duration-400" 
                             x-transition:leave-start="translate-x-0" 
                             x-transition:leave-end="translate-x-full" 
                             class="pointer-events-auto w-screen max-w-lg">
                            
                            <div class="flex h-full flex-col overflow-y-scroll bg-gray-50 shadow-2xl">
                                
                                <!-- Loading State -->
                                <div x-show="loadingView" class="p-8 flex flex-col items-center justify-center min-h-[400px] text-gray-400">
                                    <svg class="animate-spin h-8 w-8 text-blue-600 mb-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <span class="text-xs font-medium">Fetching customer profile...</span>
                                </div>

                                <!-- Rendered View Customer Content -->
                                <div x-show="!loadingView" x-html="customerHtml"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- ─── END SIDE-OVERLAY (VIEW CUSTOMER) ─── -->

    </div>
</x-app-layout>
