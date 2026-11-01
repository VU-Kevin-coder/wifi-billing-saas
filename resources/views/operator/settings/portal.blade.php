<div x-data="{
        primaryColor: '{{ $settings['primary_color'] ?? '#d91c5c' }}',
        secondaryColor: '{{ $settings['secondary_color'] ?? '#ffffff' }}',
        template: '{{ $settings['template'] ?? 'rose' }}',
        portalTitle: '{{ $settings['portal_title'] ?? $tenant->name }}',
        portalSubtitle: '{{ $settings['portal_subtitle'] ?? 'Connect to the internet' }}',
        showPackages: {{ ($settings['show_packages'] ?? true) ? 'true' : 'false' }},
        showDeviceReg: {{ ($settings['show_device_registration'] ?? true) ? 'true' : 'false' }},
        showTrouble: {{ ($settings['show_troubleshooting'] ?? true) ? 'true' : 'false' }},
        supportPhone: '{{ $settings['support_phone'] ?? '+256 791346328' }}',
        templates: [
            { id: 'rose', name: 'Rose', color: '#d91c5c', desc: 'Warm crimson & soft rose glow' },
            { id: 'ocean', name: 'Ocean Blue', color: '#0284c7', desc: 'Vibrant azure & clean cyan' },
            { id: 'midnight', name: 'Midnight Gold', color: '#f59e0b', desc: 'Deep black & metallic gold' },
            { id: 'emerald', name: 'Forest Emerald', color: '#10b981', desc: 'Lush green & fresh mint' },
            { id: 'sunset', name: 'Sunset Amber', color: '#ea580c', desc: 'Warm orange & golden radiance' },
            { id: 'royal', name: 'Royal Purple', color: '#8b5cf6', desc: 'Deep lavender & neon violet' },
            { id: 'dark', name: 'Dark Slate', color: '#334155', desc: 'Modern minimal dark mode' },
            { id: 'cyan', name: 'Neon Cyber', color: '#06b6d4', desc: 'High-contrast glowing cyan' }
        ],

        selectTemplate(t) {
            this.template = t.id;
            this.primaryColor = t.color;
        }
    }">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            
            <div class="flex flex-col lg:flex-row gap-8 items-start">
                
                <!-- Left Column: The Form -->
                <div class="w-full lg:w-7/12 space-y-6">
                    
                    <form method="POST" action="{{ route('operator.settings.portal.update', ['tenant' => $tenant->slug]) }}">
                        @csrf
                        @method('PUT')

                        <!-- 1. Basic Branding Settings -->
                        <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-200 mb-6">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-base font-bold text-gray-900 flex items-center gap-2">
                                    <div class="w-7 h-7 rounded-lg bg-blue-50 flex items-center justify-center text-blue-600">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>
                                    </div>
                                    Portal Branding & Colors
                                </h3>
                                <span class="text-xs bg-gray-100 text-gray-600 px-2.5 py-1 rounded-full font-medium">Customizable</span>
                            </div>
                            <p class="text-xs text-gray-500 mb-5">Personalize the colors, title, and theme of your customer sign-in portal.</p>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                                <!-- Portal Title -->
                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-1">Portal Name / Title</label>
                                    <input type="text" name="portal_title" x-model="portalTitle" 
                                           class="block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-blue-500 focus:ring-blue-500">
                                </div>

                                <!-- Subtitle / Tagline -->
                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-1">Tagline / Subtitle</label>
                                    <input type="text" name="portal_subtitle" x-model="portalSubtitle" 
                                           class="block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-blue-500 focus:ring-blue-500">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                                <!-- Primary Color -->
                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-1">Primary Brand Colour</label>
                                    <div class="flex items-center gap-2">
                                        <input type="color" x-model="primaryColor" class="h-10 w-12 border border-gray-300 rounded-lg cursor-pointer p-0.5 bg-white">
                                        <input type="text" name="primary_color" x-model="primaryColor" 
                                               class="block w-full rounded-lg border-gray-300 shadow-sm font-mono text-sm uppercase focus:border-blue-500 focus:ring-blue-500">
                                    </div>
                                </div>

                                <!-- Template Selector Dropdown -->
                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-1">Active Template</label>
                                    <select name="template" x-model="template" 
                                            @change="primaryColor = templates.find(t => t.id === template)?.color || primaryColor"
                                            class="block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-blue-500 focus:ring-blue-500 bg-white">
                                        <template x-for="t in templates" :key="t.id">
                                            <option :value="t.id" x-text="t.name"></option>
                                        </template>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- 2. Interactive Template Gallery -->
                        <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-200 mb-6">
                            <h3 class="text-base font-bold text-gray-900 mb-1 flex items-center gap-2">
                                <div class="w-7 h-7 rounded-lg bg-purple-50 flex items-center justify-center text-purple-600">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
                                </div>
                                Template Gallery
                            </h3>
                            <p class="text-xs text-gray-500 mb-4">Click a preset design below to preview and apply it to your portal instantly.</p>

                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                <template x-for="t in templates" :key="t.id">
                                    <div @click="selectTemplate(t)"
                                         :class="template === t.id ? 'ring-2 ring-blue-600 border-transparent shadow-md' : 'border-gray-200 hover:border-gray-300'"
                                         class="cursor-pointer border rounded-xl p-3 text-center transition-all duration-150 bg-gray-50/50 hover:bg-white group relative">
                                        
                                        <!-- Active Checkmark Badge -->
                                        <div x-show="template === t.id" class="absolute top-2 right-2 w-4 h-4 rounded-full bg-blue-600 text-white flex items-center justify-center">
                                            <svg class="w-2.5 h-2.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                        </div>

                                        <!-- Color Swatch Circle -->
                                        <div class="w-10 h-10 rounded-full mx-auto mb-2 shadow-inner border border-white flex items-center justify-center"
                                             :style="`background-color: ${t.color}`">
                                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0"/></svg>
                                        </div>
                                        <p class="font-bold text-xs text-gray-900 group-hover:text-blue-600 transition-colors" x-text="t.name"></p>
                                        <p class="text-[10px] text-gray-400 font-mono mt-0.5" x-text="t.color"></p>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <!-- 3. Customer Experience & Features -->
                        <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-200 mb-6">
                            <h3 class="text-base font-bold text-gray-900 mb-1 flex items-center gap-2">
                                <div class="w-7 h-7 rounded-lg bg-green-50 flex items-center justify-center text-green-600">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
                                </div>
                                Portal Features & Controls
                            </h3>
                            <p class="text-xs text-gray-500 mb-5">Toggle portal sections and self-service capabilities for guests.</p>
                            
                            <div class="space-y-3 mb-5 divide-y divide-gray-100">
                                <label class="flex items-center justify-between pt-2 cursor-pointer">
                                    <div>
                                        <span class="text-sm font-semibold text-gray-800">Show packages on portal</span>
                                        <p class="text-xs text-gray-500">Allow customers to browse and buy available data plans.</p>
                                    </div>
                                    <input type="checkbox" name="show_packages" value="1" x-model="showPackages"
                                           class="rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500 h-5 w-5">
                                </label>

                                <label class="flex items-center justify-between pt-3 cursor-pointer">
                                    <div>
                                        <span class="text-sm font-semibold text-gray-800">Smart TV & Console box</span>
                                        <p class="text-xs text-gray-500">Display secondary registration link for browser-less devices.</p>
                                    </div>
                                    <input type="checkbox" name="show_device_registration" value="1" x-model="showDeviceReg"
                                           class="rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500 h-5 w-5">
                                </label>

                                <label class="flex items-center justify-between pt-3 cursor-pointer">
                                    <div>
                                        <span class="text-sm font-semibold text-gray-800">Troubleshooting collapsible</span>
                                        <p class="text-xs text-gray-500">Provide an expandable 'Connection Issues?' FAQ guide.</p>
                                    </div>
                                    <input type="checkbox" name="show_troubleshooting" value="1" x-model="showTrouble"
                                           class="rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500 h-5 w-5">
                                </label>
                            </div>

                            <!-- Support Phone -->
                            <div class="pt-2 border-t border-gray-100">
                                <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-1">Helpdesk / Support Phone Number</label>
                                <input type="text" name="support_phone" x-model="supportPhone" 
                                       placeholder="+256 700 000000"
                                       class="block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-blue-500 focus:ring-blue-500">
                                <p class="text-xs text-gray-400 mt-1">Displayed in the footer of your customer captive portal.</p>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex items-center justify-end gap-3 pt-2">
                            <a href="{{ route('operator.settings.portal') }}" class="px-5 py-2.5 rounded-xl border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition-colors">
                                Reset Changes
                            </a>
                            <button type="submit" class="bg-blue-600 text-white px-7 py-2.5 rounded-xl text-sm font-bold hover:bg-blue-700 shadow-sm transition-all duration-150 flex items-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                Save Portal Settings
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Right Column: Interactive Phone Mockup -->
                <div class="w-full lg:w-5/12">
                    <div class="sticky top-6">
                        <div class="flex items-center justify-between mb-3 px-1">
                            <span class="text-xs font-bold text-gray-500 uppercase tracking-wider flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-green-500 animate-pulse"></span>
                                Live Customer Preview
                            </span>
                            <span class="text-xs text-gray-400" x-text="`Template: ${template.toUpperCase()}`"></span>
                        </div>
                        
                        <!-- Phone Frame -->
                        <div class="w-full max-w-[340px] mx-auto bg-gray-900 rounded-[40px] p-2.5 shadow-2xl border-[4px] border-gray-800">
                            <!-- Screen Bezel -->
                            <div class="w-full bg-white rounded-[32px] overflow-hidden shadow-inner border border-gray-100 flex flex-col">
                                
                                <!-- Phone Notch & Speaker -->
                                <div class="bg-gray-900 h-5 w-full flex items-center justify-center">
                                    <div class="w-16 h-2.5 bg-black rounded-b-md"></div>
                                </div>

                                <!-- Dynamic Header Banner -->
                                <div class="pt-6 pb-5 px-5 text-center rounded-b-[24px] shadow-sm relative overflow-hidden transition-colors duration-300" 
                                     :style="`background-color: ${primaryColor}`">
                                    <div class="inline-flex items-center justify-center w-12 h-12 bg-white/20 rounded-full mb-2 backdrop-blur-sm">
                                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"></path>
                                        </svg>
                                    </div>
                                    <h1 class="text-xl font-bold text-white tracking-tight leading-tight" x-text="portalTitle.toLowerCase()"></h1>
                                    <p class="text-white/80 text-[11px] font-medium mt-0.5" x-text="portalSubtitle"></p>
                                </div>

                                <!-- Body -->
                                <div class="p-4 space-y-3 bg-white text-left">
                                    <h2 class="text-center text-[10px] font-bold text-gray-500 tracking-wider">HAVE A VOUCHER?</h2>
                                    
                                    <div class="relative">
                                        <input type="text" placeholder="Enter voucher code" disabled 
                                               class="block w-full py-2 px-3 border border-gray-200 rounded-lg text-xs bg-gray-50/70 text-gray-400">
                                    </div>

                                    <button disabled 
                                            class="w-full text-white py-2.5 rounded-lg font-bold text-xs shadow-sm transition-colors duration-300" 
                                            :style="`background-color: ${primaryColor}`">
                                        Connect
                                    </button>

                                    <div class="border rounded-lg py-1.5 text-center text-xs font-bold transition-colors duration-300 flex items-center justify-center gap-1"
                                         :style="`border-color: ${primaryColor}; color: ${primaryColor}`">
                                        <span>🔍 Already bought? Find voucher</span>
                                    </div>

                                    <!-- Divider -->
                                    <div class="relative flex items-center py-0.5" x-show="showPackages">
                                        <div class="flex-grow border-t border-gray-200"></div>
                                        <span class="flex-shrink-0 mx-2 text-[10px] text-gray-400">or purchase a package</span>
                                        <div class="flex-grow border-t border-gray-200"></div>
                                    </div>

                                    <!-- Package List Preview -->
                                    <div x-show="showPackages" class="border border-gray-100 rounded-xl overflow-hidden shadow-xs">
                                        <div class="bg-gray-50 px-3 py-1.5 flex justify-between text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                            <span>Package</span>
                                            <span>Price</span>
                                        </div>
                                        <div class="divide-y divide-gray-100">
                                            <div class="px-3 py-2 flex justify-between items-center text-xs">
                                                <div>
                                                    <p class="font-bold text-gray-900 text-[11px]">Daily Express</p>
                                                    <p class="text-[9px] text-gray-400">1 Day · 5 Mbps</p>
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    <span class="font-bold text-gray-900 text-[11px]">Ush 1,000</span>
                                                    <span class="text-white text-[9px] font-bold px-2 py-0.5 rounded shadow-xs" :style="`background-color: ${primaryColor}`">BUY</span>
                                                </div>
                                            </div>
                                            <div class="px-3 py-2 flex justify-between items-center text-xs">
                                                <div>
                                                    <p class="font-bold text-gray-900 text-[11px]">Weekly Connect</p>
                                                    <p class="text-[9px] text-gray-400">7 Days · 10 Mbps</p>
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    <span class="font-bold text-gray-900 text-[11px]">Ush 6,000</span>
                                                    <span class="text-white text-[9px] font-bold px-2 py-0.5 rounded shadow-xs" :style="`background-color: ${primaryColor}`">BUY</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Smart TV box preview -->
                                    <div x-show="showDeviceReg" class="bg-gray-50 border border-gray-100 rounded-lg p-2 text-[10px] text-gray-600 flex items-center gap-1.5">
                                        <span>📺</span>
                                        <span>Smart TV or Console? <span class="font-bold underline" :style="`color: ${primaryColor}`">Register here</span></span>
                                    </div>

                                    <!-- Troubleshooting preview -->
                                    <div x-show="showTrouble" class="bg-gray-50 border border-gray-100 rounded-lg p-2 text-[10px] font-bold flex items-center justify-between text-gray-700">
                                        <span>❓ Connection Issues?</span>
                                        <span class="text-gray-400">⌵</span>
                                    </div>

                                    <!-- Support phone -->
                                    <div class="pt-1 text-center text-[10px] text-gray-500">
                                        Need help? Call <span class="font-bold" :style="`color: ${primaryColor}`" x-text="supportPhone"></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

            </div>

        </div>
    </div>
