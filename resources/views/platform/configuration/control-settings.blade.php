<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Platform control settings</h2>
    </x-slot>

    @php
        $sectionList = collect($catalog['sections']);
        $activeSection = $sectionList->firstWhere('id', $section) ?? $sectionList->first();
        $fields = collect($catalog['fields'])->filter(fn ($field) => $field['section'] === $activeSection['id']);
        $fieldGroups = $fields->groupBy('group', true);
        $permissionNames = array_keys($catalog['permission_labels']);
        $agentTokens = session('control_action_result.tokens', []);
    @endphp

    <style>
        .control-page{--bg:#f5f7f8;--surface:#fff;--surface2:#eef2f4;--line:#dde3e7;--line2:#c5cdd3;--fg:#16202a;--fg2:#4a5966;--fg3:#73818d;--accent:#0b6e8a;--accent-soft:#dcf0f6;--ok:#17794a;--warn:#9a5b00;--bad:#b42318;background:var(--bg);color:var(--fg);min-height:calc(100vh - 64px);font:14px/1.5 Inter,system-ui,-apple-system,"Segoe UI",sans-serif}
        .control-page *{box-sizing:border-box}.control-page button,.control-page input,.control-page select,.control-page textarea{font:inherit;color:inherit}.control-page a{color:var(--accent)}
        .control-top{display:flex;align-items:center;gap:14px;flex-wrap:wrap;padding:14px max(16px,calc((100vw - 1280px)/2));border-bottom:1px solid var(--line);background:var(--surface)}
        .control-brand{font-weight:700;white-space:nowrap}.control-brand span{color:var(--fg3);font-weight:500}.control-search{position:relative;flex:1 1 260px;max-width:440px}.control-search input{width:100%;padding:8px 12px;border:1px solid var(--line2);border-radius:8px;background:var(--bg)}
        .control-wrap{display:grid;grid-template-columns:240px minmax(0,1fr);gap:28px;padding:22px 18px 128px;max-width:1280px;margin:auto}
        .control-nav{position:sticky;top:82px;align-self:start;max-height:calc(100vh - 100px);overflow:auto;padding-right:4px}.control-nav h6{margin:16px 0 6px;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:var(--fg3);font-weight:600}.control-nav h6:first-child{margin-top:0}
        .control-nav a{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:7px 10px;border-radius:8px;color:var(--fg2);text-decoration:none;font-weight:500}.control-nav a:hover{background:var(--surface2)}.control-nav a.active{background:var(--accent-soft);color:var(--accent)}
        .control-dot,.control-tag{font-size:10px;font-weight:700;padding:2px 7px;border-radius:99px;background:var(--accent-soft);color:var(--accent)}.control-tag{background:#fdf0d5;color:var(--warn)}
        .control-content{min-width:0}.control-head{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:flex-start;margin-bottom:16px}.control-head h1{font-size:22px;margin:0 0 4px;letter-spacing:-.02em}.control-head p{margin:0;color:var(--fg2);max-width:72ch}
        .control-card{background:var(--surface);border:1px solid var(--line);border-radius:12px;margin-bottom:16px;box-shadow:0 1px 2px #1020300f,0 6px 20px #1020300f;overflow:hidden}
        .control-card>header{padding:14px 18px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}.control-card>header h2{margin:0;font-size:15px}.control-card>header p{margin:2px 0 0;color:var(--fg3);font-size:12px}
        .control-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(200px,320px);gap:10px 28px;padding:14px 18px;border-top:1px solid var(--line);align-items:start}.control-card>header+.control-row{border-top:0}.control-row.changed{background:linear-gradient(90deg,var(--accent-soft),transparent 40%)}
        .control-label{font-weight:600;display:flex;align-items:center;gap:8px;flex-wrap:wrap}.control-help{color:var(--fg2);font-size:12px;margin-top:3px;max-width:65ch}.control-key{font:11px ui-monospace,Menlo,Consolas,monospace;color:var(--fg3);background:var(--surface2);border-radius:5px;padding:1px 6px;font-weight:400}
        .control-live,.control-env{font-size:10px;font-weight:700;padding:1px 7px;border-radius:99px;background:#dff3e8;color:var(--ok)}.control-env{background:#fdf0d5;color:var(--warn)}.control-planned{background:#fdf0d5;color:var(--warn)}
        .control-input{width:100%;padding:8px 10px;border:1px solid var(--line2);border-radius:8px;background:var(--bg)}.control-input:focus{outline:2px solid var(--accent);outline-offset:1px}.control-textarea{min-height:76px;resize:vertical;font:12px/1.5 ui-monospace,Menlo,Consolas,monospace}
        .control-unit{display:flex;align-items:center;gap:8px}.control-unit span{color:var(--fg3);font-size:12px;white-space:nowrap}
        .control-toggle{display:flex;gap:10px;align-items:center}.control-toggle input{width:18px;height:18px;accent-color:var(--accent)}.control-toggle small{color:var(--fg3)}
        .control-actions{display:flex;gap:8px;flex-wrap:wrap}.control-btn{border:1px solid var(--line2);background:var(--surface);border-radius:8px;padding:7px 12px;cursor:pointer;font-weight:600;text-decoration:none}.control-btn:hover{background:var(--surface2)}.control-btn.primary{background:var(--accent);border-color:var(--accent);color:white}.control-btn.danger{border-color:var(--bad);color:var(--bad)}.control-btn:disabled{opacity:.5;cursor:not-allowed}
        .control-banner{padding:11px 14px;border-radius:10px;margin-bottom:16px;background:var(--accent-soft)}.control-banner.warn{background:#fdf0d5}.control-banner.bad{background:#fde4e1}.control-note{padding:12px 18px;color:var(--fg2);background:var(--surface2);border-radius:0 0 12px 12px}
        .control-table-wrap{overflow:auto} .control-table{border-collapse:collapse;width:100%;min-width:560px}.control-table th,.control-table td{text-align:left;padding:10px 14px;border-top:1px solid var(--line);vertical-align:middle}.control-table th{border-top:0;color:var(--fg3);font-size:10px;text-transform:uppercase;letter-spacing:.06em}.control-table input[type=checkbox]{width:17px;height:17px;accent-color:var(--accent)}
        .control-status{display:inline-block;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;background:#dff3e8;color:var(--ok)}.control-status.warn{background:#fdf0d5;color:var(--warn)}.control-status.bad{background:#fde4e1;color:var(--bad)}.control-empty{padding:28px 18px;text-align:center;color:var(--fg3)}
        .control-danger-row{padding:14px 18px;border-top:1px solid var(--line);display:grid;grid-template-columns:minmax(0,1fr) minmax(180px,300px);gap:10px 20px;align-items:center}.control-danger-row form{display:flex;gap:6px;flex-wrap:wrap}
        .control-savebar{position:fixed;left:0;right:0;bottom:0;z-index:40;display:none;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap;padding:12px 16px;background:var(--surface);border-top:1px solid var(--line2);box-shadow:0 -8px 24px #0002}.control-savebar.show{display:flex}.control-savebar input{width:min(420px,100%);padding:8px 10px;border:1px solid var(--line2);border-radius:8px;background:var(--bg)}
        @media(max-width:900px){.control-wrap{grid-template-columns:minmax(0,1fr);gap:14px}.control-nav{position:static;max-height:none;display:flex;gap:6px;overflow-x:auto;padding-bottom:6px}.control-nav h6{display:none}.control-nav a{white-space:nowrap;border:1px solid var(--line);background:var(--surface)}.control-row,.control-danger-row{grid-template-columns:minmax(0,1fr)}}
        @media(prefers-color-scheme:dark){.control-page:not([data-theme=light]){--bg:#0f161b;--surface:#161f26;--surface2:#1d2830;--line:#26333c;--line2:#364652;--fg:#e6edf1;--fg2:#a7b4be;--fg3:#7d8b96;--accent:#4fb8d6;--accent-soft:#12323d}}
        .control-page[data-theme=dark]{--bg:#0f161b;--surface:#161f26;--surface2:#1d2830;--line:#26333c;--line2:#364652;--fg:#e6edf1;--fg2:#a7b4be;--fg3:#7d8b96;--accent:#4fb8d6;--accent-soft:#12323d}
    </style>

    <div class="control-page" id="controlPage">
        <div class="control-top">
            <div class="control-brand">WiFi Billing <span>/ Platform admin / Control settings</span></div>
            <div class="control-search"><input type="search" id="settingSearch" placeholder="Search settings or keys…" aria-label="Search settings"></div>
            <div class="control-actions">
                <a class="control-btn" href="{{ route('platform.policies.index') }}">Policy center</a>
                <a class="control-btn" href="{{ route('platform.configuration.export') }}">Export JSON</a>
                <button class="control-btn" type="button" id="themeToggle">Theme: auto</button>
            </div>
        </div>

        <div class="control-wrap">
            <nav class="control-nav" aria-label="Control setting sections">
                @php $lastGroup = null; @endphp
                @foreach($catalog['sections'] as $item)
                    @if($item['group'] !== $lastGroup)
                        <h6>{{ $item['group'] }}</h6>
                        @php $lastGroup = $item['group']; @endphp
                    @endif
                    <a href="{{ route('platform.configuration.control', ['section' => $item['id']]) }}"
                       class="{{ $item['id'] === $activeSection['id'] ? 'active' : '' }}">
                        <span>{{ $item['title'] }}</span>
                        @if(!empty($item['planned']))<span class="control-tag">Planned</span>@endif
                    </a>
                @endforeach
            </nav>

            <main class="control-content">
                @if(session('status'))
                    <div class="control-banner">{{ session('status') }}</div>
                @endif
                @if($errors->any())
                    <div class="control-banner bad">{{ $errors->first() }}</div>
                @endif
                <div class="control-head">
                    <div>
                        <h1>{{ $activeSection['title'] }} @if(!empty($activeSection['planned']))<span class="control-tag">Planned</span>@endif</h1>
                        <p>{{ $activeSection['description'] }}</p>
                    </div>
                    @if(in_array($activeSection['id'], ['general','tenants','payments','wallet','refunds','vouchers','provisioning','routers','vpn','sms','email','security','audit']))
                        <button class="control-btn" type="button" id="resetSection">Reset section</button>
                    @endif
                    @if($activeSection['id'] === 'payments')
                        <span class="control-status warn">Do not enable live payments before testing provider callbacks.</span>
                    @endif
                </div>

                @if($activeSection['id'] === 'general' || $activeSection['id'] === 'provisioning')
                    <div class="control-banner warn">
                        Controls marked <b>Needs redeploy</b> are stored as requested values only. They do not override the running environment.
                        Controls marked <b>Live</b> or without a source badge are stored in the database.
                    </div>
                @endif
                @if($activeSection['id'] === 'vpn')
                    <div class="control-banner warn">
                        WireGuard peers can be managed from the existing <a href="{{ route('platform.vpn-peers.index') }}">VPN peers</a> screen.
                        These hub settings are stored for reference and do not override host-level WireGuard configuration.
                    </div>
                @endif

                @if(in_array($activeSection['id'], ['general','tenants','payments','wallet','refunds','vouchers','provisioning','routers','vpn','sms','email','security','audit']))
                    <form id="settingsForm" method="POST" action="{{ route('platform.configuration.control.update') }}">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="section" value="{{ $activeSection['id'] }}">
                        @foreach($fieldGroups as $groupName => $groupFields)
                            <section class="control-card" data-group>
                                <header><div><h2>{{ $groupName }}</h2></div></header>
                                @foreach($groupFields as $key => $field)
                                    @php
                                        $value = $settings[$key];
                                        $isPolicy = in_array($field['type'], ['policy','policy_select'], true);
                                        $fieldName = $isPolicy ? "policies[$key]" : "settings[$key]";
                                        $searchText = strtolower($field['label'].' '.$key.' '.($field['help'] ?? '').' '.($field['env'] ?? ''));
                                    @endphp
                                    <div class="control-row" data-setting-row data-search="{{ $searchText }}">
                                        <div>
                                            <div class="control-label">
                                                {{ $field['label'] }}
                                                @if($isPolicy)<span class="control-live">Live policy</span>@endif
                                                @if(($field['source'] ?? '') === 'env')<span class="control-env">Needs redeploy</span>@endif
                                                @if(!empty($field['planned']))<span class="control-tag">Planned</span>@endif
                                                @if(!empty($field['danger']))<span class="control-status bad">Production-critical</span>@endif
                                                <span class="control-key">{{ $key }}</span>
                                            </div>
                                            @if(!empty($field['help']))<div class="control-help">{{ $field['help'] }}</div>@endif
                                            @if(($field['source'] ?? '') === 'env')<div class="control-help">Requested value is saved for deployment; the active environment remains unchanged.</div>@endif
                                            @if(!empty($field['planned']))<div class="control-help">This request is saved for future deployment; it does not change the running system.</div>@endif
                                        </div>
                                        <div>
                                            @if($field['type'] === 'boolean' || $field['type'] === 'policy')
                                                <div class="control-toggle">
                                                    <input type="hidden" name="{{ $fieldName }}" value="0">
                                                    <input type="checkbox" data-setting-input data-reset-default="{{ (int) (bool) $field['default'] }}" name="{{ $fieldName }}" value="1" @checked((bool) $value) aria-label="{{ $field['label'] }}">
                                                    <small>{{ $value ? 'On' : 'Off' }}</small>
                                                </div>
                                            @elseif($field['type'] === 'select' || $field['type'] === 'policy_select')
                                                <select class="control-input" data-setting-input data-reset-default="{{ $field['default'] }}" name="{{ $fieldName }}" aria-label="{{ $field['label'] }}">
                                                    @foreach($field['options'] as $option)
                                                        <option value="{{ $option }}" @selected((string) $value === (string) $option)>{{ $option }}</option>
                                                    @endforeach
                                                </select>
                                            @elseif($field['type'] === 'number')
                                                <div class="control-unit">
                                                    <input class="control-input" type="number" data-setting-input data-reset-default="{{ $field['default'] }}" name="{{ $fieldName }}" value="{{ $value }}" @isset($field['min']) min="{{ $field['min'] }}" @endisset @isset($field['max']) max="{{ $field['max'] }}" @endisset step="{{ $field['step'] ?? 1 }}" aria-label="{{ $field['label'] }}">
                                                    @if(!empty($field['unit']))<span>{{ $field['unit'] }}</span>@endif
                                                </div>
                                            @elseif($field['type'] === 'tags')
                                                <textarea class="control-input control-textarea" data-setting-input data-reset-default="{{ implode("\n", (array) ($field['default'] ?? [])) }}" name="{{ $fieldName }}" aria-label="{{ $field['label'] }}">{{ implode("\n", (array) $value) }}</textarea>
                                            @elseif($field['type'] === 'textarea')
                                                <textarea class="control-input control-textarea" data-setting-input data-reset-default="{{ $field['default'] ?? '' }}" name="{{ $fieldName }}" aria-label="{{ $field['label'] }}">{{ $value }}</textarea>
                                            @elseif($field['type'] === 'secret')
                                                <input class="control-input" type="password" data-setting-input name="{{ $fieldName }}" value="" autocomplete="new-password" placeholder="{{ $value ? 'Saved — type to replace' : 'Not set' }}" aria-label="{{ $field['label'] }}">
                                            @elseif($field['type'] === 'readonly')
                                                <input class="control-input" type="text" value="{{ $value }}" readonly aria-label="{{ $field['label'] }}">
                                            @else
                                                <input class="control-input" type="{{ $field['type'] }}" data-setting-input data-reset-default="{{ $field['default'] ?? '' }}" name="{{ $fieldName }}" value="{{ $value }}" @isset($field['max']) maxlength="{{ $field['max'] }}" @endisset aria-label="{{ $field['label'] }}">
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </section>
                        @endforeach
                    </form>
                @endif

                @if($activeSection['id'] === 'roles')
                    <form id="settingsForm" method="POST" action="{{ route('platform.configuration.control.update') }}">
                        @csrf @method('PUT')
                        <input type="hidden" name="section" value="roles">
                        @foreach($catalog['permissions'] as $roleName => $defaults)
                            <input type="hidden" name="permission_roles[{{ $roleName }}]" value="1">
                        @endforeach
                        <section class="control-card">
                            <header><div><h2>Permission matrix</h2><p>Platform admins keep every permission.</p></div></header>
                            <div class="control-table-wrap"><table class="control-table">
                                <thead><tr><th>Permission</th>@foreach($catalog['permissions'] as $roleName => $defaults)<th>{{ str_replace('_', ' ', $roleName) }}</th>@endforeach</tr></thead>
                                <tbody>
                                @foreach($catalog['permission_labels'] as $permission => $label)
                                    <tr><td><b>{{ $label }}</b><br><span class="control-key">{{ $permission }}</span></td>
                                        @foreach($catalog['permissions'] as $roleName => $defaults)
                                            <td>
                                                <input type="checkbox" name="permissions[{{ $roleName }}][]" value="{{ $permission }}" @checked(in_array($permission, $roles[$roleName] ?? [], true)) @disabled($roleName === 'platform_admin') aria-label="{{ $roleName }} {{ $permission }}">
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                                </tbody>
                            </table></div>
                        </section>
                    </form>
                @endif

                @if($activeSection['id'] === 'health')
                    <section class="control-card">
                        <header><div><h2>Current status</h2><p>Live probes from the application health monitor.</p></div><span class="control-status {{ $health['overall'] === 'healthy' ? '' : 'warn' }}">{{ ucfirst($health['overall']) }}</span></header>
                        <div class="control-table-wrap"><table class="control-table"><thead><tr><th>Service</th><th>Status</th><th>Details</th></tr></thead><tbody>
                            @foreach($health['checks'] as $name => $check)
                                <tr><td>{{ ucfirst($name) }}</td><td><span class="control-status {{ $check['status'] === 'healthy' ? '' : 'warn' }}">{{ ucfirst($check['status']) }}</span></td><td>{{ $check['message'] }}</td></tr>
                            @endforeach
                        </tbody></table></div>
                        <div class="control-note">Checked {{ $health['checked_at'] ? \Illuminate\Support\Carbon::parse($health['checked_at'])->diffForHumans() : 'not yet' }}. Status refreshes when this page loads.</div>
                    </section>
                    <section class="control-card">
                        <header><div><h2>Site agents</h2><p>Agents report their actual last poll and paired site.</p></div></header>
                        @if($agents->isEmpty())<div class="control-empty">No site agents are paired yet.</div>
                        @else<div class="control-table-wrap"><table class="control-table"><thead><tr><th>Agent</th><th>Site</th><th>Tenant</th><th>Last poll</th><th>Status</th></tr></thead><tbody>
                            @foreach($agents as $agent)
                                @php $online = $agent->revoked_at === null && $agent->last_seen_at && $agent->last_seen_at->greaterThan(now()->subMinutes((int) ($settings['alerts.agent_offline_after'] ?? 15))); @endphp
                                <tr><td>{{ $agent->name }}</td><td>{{ $agent->site?->name ?? '—' }}</td><td>{{ $agent->tenant?->name ?? '—' }}</td><td>{{ $agent->last_seen_at?->diffForHumans() ?? 'Never' }}</td><td><span class="control-status {{ $online ? '' : 'warn' }}">{{ $agent->revoked_at ? 'Revoked' : ($online ? 'Online' : 'Offline') }}</span></td></tr>
                            @endforeach
                        </tbody></table></div>@endif
                    </section>
                @endif

                @if($activeSection['id'] === 'danger')
                    @php
                        $dangerActions = [
                            ['rotate', 'Rotate all agent tokens', 'Existing agents will stop authenticating until configured with their new one-time token.', 'ROTATE'],
                            ['purge', 'Purge expired sessions now', 'Deletes expired session history older than the configured retention period.', 'PURGE'],
                            ['freeze', 'Suspend all payouts', 'Blocks new operator payout requests and payout approvals until resumed.', 'FREEZE'],
                            ['resume', 'Resume payouts', 'Allows new operator payout requests and payout approvals.', 'RESUME'],
                            ['reset', 'Reset settings to defaults', 'Reverts configurable values to their seeded defaults. Credentials are retained.', 'RESET'],
                        ];
                    @endphp
                    <section class="control-card" style="border-color:var(--bad)">
                        <header><div><h2 style="color:var(--bad)">Dangerous actions</h2><p>Each action is audited. Type the confirmation word and give a reason.</p></div></header>
                        @foreach($dangerActions as [$action, $label, $description, $word])
                            @if(($action !== 'freeze' || !($settings['payouts.paused'] ?? false)) && ($action !== 'resume' || ($settings['payouts.paused'] ?? false)))
                            <div class="control-danger-row">
                                <div><b>{{ $label }}</b><div class="control-help">{{ $description }}</div></div>
                                <form method="POST" action="{{ route('platform.configuration.control.action', $action) }}">
                                    @csrf
                                    <input type="text" name="confirmation" class="control-input" placeholder="Type {{ $word }}" required>
                                    <input type="text" name="reason" class="control-input" placeholder="Audit reason (min 5 chars)" minlength="5" maxlength="200" required>
                                    <button class="control-btn danger" type="submit">Confirm</button>
                                </form>
                            </div>
                            @endif
                        @endforeach
                    </section>
                    @if(count($agentTokens))
                        <section class="control-card">
                            <header><div><h2>New agent tokens</h2><p>Shown once. Copy each token to its site agent and store it securely.</p></div></header>
                            @foreach($agentTokens as $item)
                                <div class="control-row"><div><b>{{ $item['site'] }}</b></div><div><code>{{ $item['token'] }}</code></div></div>
                            @endforeach
                        </section>
                    @endif
                @endif

                @if($fields->isEmpty() && !in_array($activeSection['id'], ['roles','health','danger']))
                    <section class="control-card"><div class="control-empty">No settings are defined in this section.</div></section>
                @endif
            </main>
        </div>

        @if(isset($history) && $activeSection['id'] === 'audit')
            <div class="control-wrap" style="padding-top:0">
                <section class="control-card" style="grid-column:2">
                    <header><div><h2>Recent changes</h2><p>Settings changes and dangerous actions with their reason.</p></div></header>
                    @if($history->isEmpty())<div class="control-empty">No platform setting changes have been recorded.</div>
                    @else<div class="control-table-wrap"><table class="control-table"><thead><tr><th>When</th><th>Admin</th><th>Action</th><th>Reason</th></tr></thead><tbody>
                        @foreach($history as $entry)
                            <tr><td>{{ $entry->created_at->format('Y-m-d H:i') }}</td><td>{{ $entry->user?->name ?? 'System' }}</td><td>{{ $entry->action }}</td><td>{{ $entry->meta['reason'] ?? '—' }}</td></tr>
                        @endforeach
                    </tbody></table></div>@endif
                </section>
            </div>
        @endif

        <div class="control-savebar" id="saveBar" role="region" aria-label="Unsaved changes">
            <b id="dirtyLabel">Unsaved changes</b>
            <input id="auditReason" form="settingsForm" type="text" name="reason" placeholder="Reason for this change (required)" minlength="5" maxlength="200" required aria-label="Reason for change">
            <button type="button" class="control-btn" id="discardChanges">Discard</button>
            <button type="submit" class="control-btn primary" id="saveChanges" form="settingsForm" disabled>Save changes</button>
        </div>
    </div>

    <script>
        (() => {
            const page = document.getElementById('controlPage');
            const form = document.getElementById('settingsForm');
            const bar = document.getElementById('saveBar');
            const search = document.getElementById('settingSearch');
            const save = document.getElementById('saveChanges');
            const reason = document.getElementById('auditReason');
            let dirty = false;
            const controls = form ? [...form.querySelectorAll('[data-setting-input]')] : [];
            const valueOf = control => control.type === 'checkbox' ? (control.checked ? '1' : '0') : control.value;
            controls.forEach(control => { control.dataset.initialValue = valueOf(control); });
            const updateBar = () => {
                dirty = controls.some(control => control.dataset.initialValue !== valueOf(control));
                document.querySelectorAll('[data-setting-row]').forEach(row => {
                    row.classList.toggle('changed', [...row.querySelectorAll('[data-setting-input]')].some(control => control.dataset.initialValue !== valueOf(control)));
                });
                bar.classList.toggle('show', dirty);
                save.disabled = !dirty || reason.value.trim().length < 5;
            };
            if (form) {
                form.addEventListener('input', updateBar);
                form.addEventListener('change', updateBar);
                document.getElementById('discardChanges').addEventListener('click', () => location.reload());
                document.getElementById('resetSection')?.addEventListener('click', () => {
                    controls.forEach(control => {
                        if (!control.hasAttribute('data-reset-default')) return;
                        if (control.type === 'checkbox') {
                            control.checked = control.dataset.resetDefault === '1';
                        } else {
                            control.value = control.dataset.resetDefault;
                        }
                        control.dispatchEvent(new Event('input', { bubbles: true }));
                        control.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                });
                reason.addEventListener('input', updateBar);
                window.addEventListener('beforeunload', event => {
                    if (dirty) { event.preventDefault(); event.returnValue = ''; }
                });
            }
            search.addEventListener('input', () => {
                const query = search.value.trim().toLowerCase();
                document.querySelectorAll('[data-setting-row]').forEach(row => {
                    row.hidden = query !== '' && !row.dataset.search.includes(query);
                });
                document.querySelectorAll('[data-group]').forEach(group => {
                    group.hidden = query !== '' && !group.querySelector('[data-setting-row]:not([hidden])');
                });
            });
            const modes = ['auto', 'light', 'dark'];
            let mode = 0;
            document.getElementById('themeToggle').addEventListener('click', () => {
                mode = (mode + 1) % modes.length;
                if (modes[mode] === 'auto') page.removeAttribute('data-theme');
                else page.setAttribute('data-theme', modes[mode]);
                document.getElementById('themeToggle').textContent = 'Theme: ' + modes[mode];
            });
        })();
    </script>
</x-app-layout>
