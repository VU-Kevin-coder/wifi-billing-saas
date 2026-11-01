<x-app-layout>
    <div class="py-8 px-4 sm:px-6 lg:px-8 max-w-5xl mx-auto"
         x-data="{
             step: 1,
             hasWireless: false,
             enableHotspot: true,
             showPassword: false,
             copied: false,
             attempt: 12,
             timer: null,
             init() {
                 this.timer = setInterval(() => {
                     if (this.attempt < 300) this.attempt++;
                 }, 3000);
             },
             copyCommand() {
                 navigator.clipboard.writeText('{{ $provisionCommand }}');
                 this.copied = true;
                 setTimeout(() => this.copied = false, 2500);
             }
         }">

        <!-- Dark Container for Premium MikroTik Setup Feel -->
        <div class="bg-[#0f1117] text-gray-200 rounded-3xl p-6 sm:p-10 border border-gray-800 shadow-2xl space-y-8">
            
            <!-- Breadcrumbs & Header -->
            <div>
                <div class="flex items-center gap-2 text-xs text-gray-400 mb-2 font-medium">
                    <a href="{{ route('operator.routers.index') }}" class="hover:text-gray-200 transition-colors">Mikro Tiks</a>
                    <span>&rsaquo;</span>
                    <span class="text-orange-400 font-semibold">Create</span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight text-white">Add Mikrotik Device</h1>
                <p class="text-xs sm:text-sm text-gray-400 mt-1 max-w-2xl">
                    To proceed with the onboarding, connect your Mikrotik router to enable automated provisioning and management.
                </p>
            </div>

            <!-- Stepper Navigation -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 bg-[#161922] p-4 rounded-2xl border border-gray-800/80">
                
                <!-- Step 1 Indicator -->
                <div class="flex items-center gap-3 cursor-pointer" @click="step = 1">
                    <div class="w-9 h-9 rounded-full flex items-center justify-center font-bold text-xs shrink-0 transition-colors"
                         :class="step >= 1 ? 'bg-orange-500 text-white shadow-lg shadow-orange-500/20' : 'bg-gray-800 text-gray-400'">
                        <span x-show="step > 1">✓</span>
                        <span x-show="step <= 1">01</span>
                    </div>
                    <div>
                        <p class="text-xs font-bold" :class="step >= 1 ? 'text-white' : 'text-gray-400'">Connection</p>
                        <p class="text-[11px] text-gray-500">Basic device information</p>
                    </div>
                </div>

                <!-- Step 2 Indicator -->
                <div class="flex items-center gap-3 cursor-pointer" @click="step = 2">
                    <div class="w-9 h-9 rounded-full flex items-center justify-center font-bold text-xs shrink-0 transition-colors"
                         :class="step >= 2 ? 'bg-orange-500 text-white shadow-lg shadow-orange-500/20' : 'bg-gray-800 text-gray-400'">
                        <span x-show="step > 2">✓</span>
                        <span x-show="step <= 2">02</span>
                    </div>
                    <div>
                        <p class="text-xs font-bold" :class="step >= 2 ? 'text-white' : 'text-gray-400'">Device Details</p>
                        <p class="text-[11px] text-gray-500">Provisioning command</p>
                    </div>
                </div>

                <!-- Step 3 Indicator -->
                <div class="flex items-center gap-3 cursor-pointer" @click="step = 3">
                    <div class="w-9 h-9 rounded-full flex items-center justify-center font-bold text-xs shrink-0 transition-colors"
                         :class="step === 3 ? 'bg-orange-500 text-white shadow-lg shadow-orange-500/20' : 'bg-gray-800 text-gray-400'">
                        <span>03</span>
                    </div>
                    <div>
                        <p class="text-xs font-bold" :class="step === 3 ? 'text-white' : 'text-gray-400'">Service Setup</p>
                        <p class="text-[11px] text-gray-500">Configure Hotspot</p>
                    </div>
                </div>

            </div>

            <!-- Form -->
            <form method="POST" action="{{ route('operator.routers.store') }}" id="routerForm">
                @csrf

                <!-- ==================== STEP 1: CONNECTION ==================== -->
                <div x-show="step === 1" class="space-y-6">
                    <div class="bg-[#161922] p-6 sm:p-8 rounded-2xl border border-gray-800 space-y-6">
                        
                        <!-- MikroTik Identity -->
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-gray-300 mb-2">
                                MikroTik Identity <span class="text-orange-400">*</span>
                            </label>
                            <input type="text" 
                                   name="name" 
                                   value="{{ old('name', $defaultName) }}" 
                                   required 
                                   class="w-full bg-[#1e222d] border border-gray-700/80 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-orange-500 focus:ring-1 focus:ring-orange-500 transition-colors">
                            <p class="text-[11px] text-gray-500 mt-2">Auto-generated name - you can change it if needed</p>
                        </div>

                        <!-- Operating Site -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-gray-300 mb-2">
                                    Operating Site
                                </label>
                                <select name="site_id" class="w-full bg-[#1e222d] border border-gray-700/80 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-orange-500 focus:ring-1 focus:ring-orange-500 transition-colors">
                                    <option value="">Unassigned</option>
                                    @foreach ($sites as $site)
                                        <option value="{{ $site->id }}" @selected(old('site_id') == $site->id)>{{ $site->name }}</option>
                                    @endforeach
                                </select>
                                @error('site_id') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                                @if ($sites->isEmpty())
                                    <p class="mt-2 text-[11px] text-gray-500">Create a site before connecting this router.</p>
                                @endif
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-gray-300 mb-2">
                                    Hardware Model
                                </label>
                                <input type="text" 
                                       name="hardware_model" 
                                       value="{{ old('hardware_model', 'CCR2004 · ROS 7.16.2') }}" 
                                       placeholder="e.g. CCR2004, RB5009, hAP ax³"
                                       class="w-full bg-[#1e222d] border border-gray-700/80 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-orange-500 focus:ring-1 focus:ring-orange-500 transition-colors">
                            </div>
                        </div>

                        <!-- Wireless Toggle -->
                        <div class="pt-4 border-t border-gray-800">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <button type="button" 
                                            @click="hasWireless = !hasWireless"
                                            class="w-12 h-6 flex items-center rounded-full p-1 transition-colors duration-200 ease-in-out cursor-pointer"
                                            :class="hasWireless ? 'bg-orange-500' : 'bg-gray-700'">
                                        <div class="bg-white w-4 h-4 rounded-full shadow-md transform transition-transform duration-200 ease-in-out"
                                             :class="hasWireless ? 'translate-x-6' : 'translate-x-0'"></div>
                                    </button>
                                    <input type="hidden" name="has_wireless" :value="hasWireless ? 1 : 0">
                                    <div>
                                        <p class="text-sm font-bold text-white">Router has Wireless</p>
                                        <p class="text-[11px] text-gray-500">Enable this if your MikroTik router has WiFi capabilities</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- Step 1 Actions -->
                    <div class="flex items-center justify-between pt-2">
                        <a href="{{ route('operator.routers.index') }}" 
                           class="px-5 py-2.5 rounded-xl bg-gray-800 hover:bg-gray-700 text-gray-300 text-xs font-semibold transition-colors">
                            Cancel
                        </a>
                        <button type="button" 
                                @click="step = 2" 
                                class="px-7 py-2.5 rounded-xl bg-orange-500 hover:bg-orange-600 text-white text-xs font-bold shadow-lg shadow-orange-500/20 transition-colors">
                            Next
                        </button>
                    </div>
                </div>

                <!-- ==================== STEP 2: PROVISIONING COMMAND ==================== -->
                <div x-show="step === 2" class="space-y-6">
                    <div class="bg-[#161922] p-6 sm:p-8 rounded-2xl border border-gray-800 space-y-6">
                        
                        <!-- Header with Copy Button -->
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2 text-sm font-bold text-white">
                                <svg class="w-4 h-4 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                                <span>Provisioning Command</span>
                            </div>
                            <button type="button" 
                                    @click="copyCommand()" 
                                    class="inline-flex items-center gap-1.5 text-xs font-bold text-orange-400 hover:text-orange-300 transition-colors cursor-pointer">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/>
                                </svg>
                                <span x-text="copied ? 'Copied to clipboard!' : 'Click to copy'">Click to copy</span>
                            </button>
                        </div>

                        <!-- Code Terminal Box -->
                        <div class="bg-[#0b0d13] border border-gray-800 rounded-xl p-4 font-mono text-xs text-emerald-400 overflow-x-auto shadow-inner select-all">
                            <code>{{ $provisionCommand }}</code>
                        </div>

                        <!-- Device Mode Warning Callout -->
                        <div class="bg-[#1e1a17] border border-amber-900/40 rounded-xl p-4 flex items-start gap-3 text-xs text-amber-200/90 leading-relaxed">
                            <span class="w-5 h-5 rounded-full bg-amber-500/20 text-amber-400 flex items-center justify-center shrink-0 font-bold text-xs mt-0.5">i</span>
                            <div>
                                If the device reports <strong class="text-white">"device mode not allowed"</strong>, open the Mikrotik terminal and run <code class="bg-black/40 px-1.5 py-0.5 rounded text-amber-300">/system/device-mode update mode=advanced</code> , unplug the power cord for 10 seconds, then restore power before retrying the provisioning command.
                            </div>
                        </div>

                        <!-- Live Polling Animation Indicator -->
                        <div class="pt-4 border-t border-gray-800 space-y-2">
                            <div class="flex items-center gap-2.5">
                                <div class="w-5 h-5 rounded-full border-2 border-orange-500 border-t-transparent animate-spin shrink-0"></div>
                                <span class="text-sm font-bold text-white">$ Paste the provisioning command in your MikroTik terminal to begin</span>
                            </div>
                            <p class="text-xs text-gray-400 pl-7">
                                Paste and execute the command in your Mikrotik terminal. The system will automatically detect when provisioning completes.
                            </p>
                            <p class="text-xs font-mono text-gray-500 pl-7">
                                Attempt <span class="text-orange-400 font-bold" x-text="attempt">12</span> of 300
                            </p>
                        </div>

                    </div>

                    <!-- Step 2 Actions -->
                    <div class="flex items-center justify-between pt-2">
                        <button type="button" 
                                @click="step = 1" 
                                class="px-5 py-2.5 rounded-xl bg-gray-800 hover:bg-gray-700 text-gray-300 text-xs font-semibold transition-colors">
                            Back
                        </button>
                        <button type="button" 
                                @click="step = 3" 
                                class="px-7 py-2.5 rounded-xl bg-orange-500 hover:bg-orange-600 text-white text-xs font-bold shadow-lg shadow-orange-500/20 transition-colors">
                            Next
                        </button>
                    </div>
                </div>

                <!-- ==================== STEP 3: SERVICE SETUP ==================== -->
                <div x-show="step === 3" class="space-y-6">
                    
                    <!-- Ethernet Ports Card -->
                    <div class="bg-[#161922] p-6 rounded-2xl border border-gray-800 space-y-4">
                        <div>
                            <h3 class="text-sm font-bold text-white">Ethernet Ports</h3>
                            <p class="text-xs text-gray-400 mt-0.5">Select the ethernet ports to add to the bridge for client access</p>
                        </div>
                        
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-2">
                            <label class="flex items-center gap-2.5 bg-[#1e222d] border border-gray-700/60 rounded-xl p-3 cursor-pointer hover:border-orange-500 transition-colors">
                                <input type="checkbox" name="ethernet_ports[]" value="ether2" checked class="rounded border-gray-600 text-orange-500 focus:ring-orange-500">
                                <span class="text-xs font-mono font-bold text-white">ether2</span>
                            </label>
                            <label class="flex items-center gap-2.5 bg-[#1e222d] border border-gray-700/60 rounded-xl p-3 cursor-pointer hover:border-orange-500 transition-colors">
                                <input type="checkbox" name="ethernet_ports[]" value="ether3" checked class="rounded border-gray-600 text-orange-500 focus:ring-orange-500">
                                <span class="text-xs font-mono font-bold text-white">ether3</span>
                            </label>
                            <label class="flex items-center gap-2.5 bg-[#1e222d] border border-gray-700/60 rounded-xl p-3 cursor-pointer hover:border-orange-500 transition-colors">
                                <input type="checkbox" name="ethernet_ports[]" value="ether4" class="rounded border-gray-600 text-orange-500 focus:ring-orange-500">
                                <span class="text-xs font-mono font-bold text-white">ether4</span>
                            </label>
                            <label class="flex items-center gap-2.5 bg-[#1e222d] border border-gray-700/60 rounded-xl p-3 cursor-pointer hover:border-orange-500 transition-colors">
                                <input type="checkbox" name="ethernet_ports[]" value="ether5" class="rounded border-gray-600 text-orange-500 focus:ring-orange-500">
                                <span class="text-xs font-mono font-bold text-white">ether5</span>
                            </label>
                        </div>
                        <p class="text-[11px] text-gray-500">Select the interfaces to include in the bridge for client access</p>
                    </div>

                    <!-- Router Access Card -->
                    <div class="bg-[#161922] p-6 rounded-2xl border border-gray-800 space-y-4">
                        <div>
                            <h3 class="text-sm font-bold text-white">Router Access</h3>
                            <p class="text-xs text-gray-400 mt-0.5">Remote access configuration</p>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 pt-2">
                            <div>
                                <p class="text-xs font-semibold text-gray-400">Connection Mode</p>
                                <p class="text-sm font-bold text-white mt-1">WireGuard VPN</p>
                            </div>
                            <div>
                                <p class="text-xs font-semibold text-gray-400">WireGuard Tunnel IP</p>
                                <p class="text-sm font-bold text-white mt-1">Not assigned</p>
                                <p class="text-[11px] text-gray-500 mt-0.5">Secure tunnel IP for management access</p>
                            </div>
                        </div>

                        <div class="pt-4 border-t border-gray-800">
                            <p class="text-xs font-semibold text-gray-400">API Credentials</p>
                            <div class="mt-2 space-y-2 text-xs">
                                <p class="text-gray-300">
                                    Username: <strong class="text-white font-mono">sopraent_user</strong>
                                    <input type="hidden" name="api_username" value="sopraent_user">
                                </p>
                                <div class="flex items-center gap-3">
                                    <span class="text-gray-300">Password:</span>
                                    <div class="flex items-center gap-2 bg-[#1e222d] px-3 py-1 rounded-lg border border-gray-700 font-mono text-white">
                                        <span x-text="showPassword ? '{{ $apiPassword }}' : '••••••••••••'"></span>
                                        <button type="button" @click="showPassword = !showPassword" class="text-gray-400 hover:text-white">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                        </button>
                                    </div>
                                    <input type="hidden" name="api_password" value="{{ $apiPassword }}">
                                </div>
                                <p class="text-[11px] text-gray-500">API credentials for router management</p>
                            </div>
                        </div>
                    </div>

                    <!-- Hotspot Configuration Card -->
                    <div class="bg-[#161922] p-6 rounded-2xl border border-gray-800 space-y-6">
                        
                        <!-- Hotspot Enable Toggle -->
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <button type="button" 
                                        @click="enableHotspot = !enableHotspot"
                                        class="w-12 h-6 flex items-center rounded-full p-1 transition-colors duration-200 ease-in-out cursor-pointer"
                                        :class="enableHotspot ? 'bg-orange-500' : 'bg-gray-700'">
                                    <div class="bg-white w-4 h-4 rounded-full shadow-md transform transition-transform duration-200 ease-in-out"
                                         :class="enableHotspot ? 'translate-x-6' : 'translate-x-0'"></div>
                                </button>
                                <input type="hidden" name="enable_hotspot" :value="enableHotspot ? 1 : 0">
                                <div>
                                    <h3 class="text-sm font-bold text-white">Enable Hotspot</h3>
                                    <p class="text-[11px] text-gray-500">Configure hotspot service on this router</p>
                                </div>
                            </div>
                        </div>

                        <!-- Hotspot Fields -->
                        <div x-show="enableHotspot" class="space-y-4 pt-2">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-300 mb-1.5">Hotspot Network *</label>
                                    <input type="text" 
                                           name="hotspot_network" 
                                           value="10.10.0.0/16" 
                                           class="w-full bg-[#1e222d] border border-gray-700/80 rounded-xl px-3 py-2 text-xs font-mono text-white focus:outline-none focus:border-orange-500">
                                    <p class="text-[10px] text-gray-500 mt-1">Network range for hotspot clients</p>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-300 mb-1.5">Gateway IP *</label>
                                    <input type="text" 
                                           name="gateway_ip" 
                                           value="10.10.0.1" 
                                           class="w-full bg-[#1e222d] border border-gray-700/80 rounded-xl px-3 py-2 text-xs font-mono text-white focus:outline-none focus:border-orange-500">
                                    <p class="text-[10px] text-gray-500 mt-1">Gateway for hotspot network</p>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-300 mb-1.5">DNS Servers *</label>
                                    <input type="text" 
                                           name="dns_servers" 
                                           value="8.8.8.8,8.8.4.4" 
                                           class="w-full bg-[#1e222d] border border-gray-700/80 rounded-xl px-3 py-2 text-xs font-mono text-white focus:outline-none focus:border-orange-500">
                                    <p class="text-[10px] text-gray-500 mt-1">DNS servers for hotspot clients</p>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-300 mb-1.5">Hotspot Login URL *</label>
                                    <div class="flex rounded-xl overflow-hidden border border-gray-700/80">
                                        <span class="inline-flex items-center px-3 bg-[#13161c] text-gray-400 text-xs font-mono">http://</span>
                                        <input type="text" 
                                               name="hotspot_domain" 
                                               value="hotspot" 
                                               class="w-full bg-[#1e222d] border-0 px-2 py-2 text-xs font-mono text-white focus:ring-0">
                                        <span class="inline-flex items-center px-3 bg-[#13161c] text-gray-400 text-xs font-mono">.local</span>
                                    </div>
                                    <p class="text-[10px] text-gray-500 mt-1">Choose what customers see when connecting (e.g. acmecafe -> http://acmecafe.local). The .local suffix is fixed — only enter the name part.</p>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- Step 3 Actions -->
                    <div class="flex items-center justify-between pt-2">
                        <button type="button" 
                                @click="step = 2" 
                                class="px-5 py-2.5 rounded-xl bg-gray-800 hover:bg-gray-700 text-gray-300 text-xs font-semibold transition-colors">
                            Back
                        </button>
                        <button type="submit" 
                                class="px-8 py-2.5 rounded-xl bg-orange-500 hover:bg-orange-600 text-white text-xs font-bold shadow-lg shadow-orange-500/20 transition-colors">
                            Create
                        </button>
                    </div>

                </div>

            </form>

        </div>

    </div>
</x-app-layout>
