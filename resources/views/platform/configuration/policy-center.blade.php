<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Platform policies</h2>
    </x-slot>

    @php
        $byKey = $policies->keyBy('key');
        $values = $policies->mapWithKeys(fn ($policy) => [$policy->key => $policy->value])->all();
        $familyMap = [
            'approvals' => ['Approval controls', 'Manual balance changes can require a second admin.', 'approval'],
            'security' => ['Sign-in and verification', 'Policies that protect administrator and operator accounts.', 'security'],
            'wallet' => ['Wallet limits', 'Limits on payouts and manual adjustments. Amounts are in UGX.', 'wallet'],
            'provisioning' => ['Router activation', 'How long a paid session may wait for the router and how often the platform retries.', 'provisioning'],
            'vouchers' => ['Voucher generation', 'Limits on voucher batches and code length.', 'voucher'],
            'scheduling' => ['Background jobs', 'How often scheduled reconciliation and expiry jobs run.', 'schedule'],
        ];
        $riskCount = 0;
        if (empty($values['security.platform_admin_mfa'])) $riskCount++;
        if (empty($values['security.step_up_enabled'])) $riskCount++;
        if (empty($values['approval.wallet_adjustment.enabled']) || ($values['approval.wallet_adjustment.mode'] ?? '') === 'never') $riskCount++;
    @endphp

    <style>
        .policy-page{--bg:#f5f7f8;--surface:#fff;--surface2:#eef2f4;--line:#dde3e7;--line2:#c5cdd3;--fg:#16202a;--fg2:#4a5966;--fg3:#73818d;--accent:#0b6e8a;--accent-soft:#dcf0f6;--ok:#17794a;--warn:#9a5b00;--bad:#b42318;background:var(--bg);color:var(--fg);min-height:calc(100vh - 64px);font:14px/1.5 Inter,system-ui,-apple-system,"Segoe UI",sans-serif}
        .policy-page *{box-sizing:border-box}.policy-page input,.policy-page select,.policy-page button{font:inherit;color:inherit}.policy-page a{color:var(--accent)}
        .policy-top{display:flex;align-items:center;gap:14px;flex-wrap:wrap;padding:14px max(16px,calc((100vw - 1100px)/2));border-bottom:1px solid var(--line);background:var(--surface)}
        .policy-brand{font-weight:700;white-space:nowrap}.policy-brand span{color:var(--fg3);font-weight:500}.policy-search{position:relative;flex:1 1 240px;max-width:380px}.policy-search input{width:100%;padding:8px 12px;border:1px solid var(--line2);border-radius:8px;background:var(--bg)}
        .policy-actions{display:flex;gap:8px}.policy-btn{border:1px solid var(--line2);background:var(--surface);border-radius:8px;padding:7px 12px;cursor:pointer;font-weight:600;text-decoration:none}.policy-btn:hover{background:var(--surface2)}.policy-btn.primary{background:var(--accent);border-color:var(--accent);color:white}.policy-btn:disabled{opacity:.5;cursor:not-allowed}
        .policy-content{max-width:1100px;margin:auto;padding:22px 18px 125px}.policy-head{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:flex-start}.policy-head h1{font-size:24px;margin:0 0 4px;letter-spacing:-.02em}.policy-head p{margin:0;color:var(--fg2);max-width:72ch}
        .policy-rail{display:flex;gap:4px;overflow-x:auto;border-bottom:1px solid var(--line);margin:18px 0 20px}.policy-rail a{display:flex;align-items:center;gap:7px;padding:10px 13px;color:var(--fg2);text-decoration:none;font-weight:600;white-space:nowrap;border-bottom:2px solid transparent;margin-bottom:-1px}.policy-rail a.active{color:var(--accent);border-color:var(--accent)}
        .policy-dot{min-width:18px;height:18px;border-radius:9px;background:var(--accent);color:white;font-size:10px;display:inline-grid;place-items:center;padding:0 5px}
        .policy-card{background:var(--surface);border:1px solid var(--line);border-radius:12px;margin-bottom:16px;box-shadow:0 1px 2px #1020300f,0 6px 20px #1020300f;overflow:hidden}.policy-card>header{padding:14px 18px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}.policy-card>header h2{margin:0;font-size:15px}.policy-card>header p{margin:2px 0 0;color:var(--fg3);font-size:12px}
        .policy-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(190px,300px);gap:10px 28px;padding:14px 18px;border-top:1px solid var(--line);align-items:start}.policy-card>header+.policy-row{border-top:0}.policy-row.changed{background:linear-gradient(90deg,var(--accent-soft),transparent 40%)}
        .policy-label{font-weight:600;display:flex;align-items:center;gap:8px;flex-wrap:wrap}.policy-key{font:11px ui-monospace,Menlo,Consolas,monospace;color:var(--fg3);background:var(--surface2);border-radius:5px;padding:1px 6px;font-weight:400}.policy-help{color:var(--fg2);font-size:12px;margin-top:3px}.policy-default{font-size:11px;color:var(--fg3);margin-top:4px}
        .policy-input{width:100%;padding:8px 10px;border:1px solid var(--line2);border-radius:8px;background:var(--bg)}.policy-input:focus{outline:2px solid var(--accent);outline-offset:1px}
        .policy-toggle{display:flex;gap:9px;align-items:center}.policy-toggle input{width:17px;height:17px;accent-color:var(--accent)}.policy-switch{width:42px;height:24px}
        .policy-table-wrap{overflow:auto}.policy-table{border-collapse:collapse;width:100%;min-width:700px}.policy-table th,.policy-table td{padding:10px 12px;border-top:1px solid var(--line);vertical-align:middle;text-align:center}.policy-table th{border-top:0;color:var(--fg3);font-size:10px;text-transform:uppercase;letter-spacing:.06em}.policy-table td:first-child,.policy-table th:first-child{text-align:left;min-width:210px}
        .policy-banner{padding:11px 14px;border-radius:10px;margin-bottom:16px;background:var(--accent-soft)}.policy-banner.warn{background:#fdf0d5}.policy-banner.bad{background:#fde4e1}
        .policy-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:16px}.policy-tile{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:12px 16px}.policy-tile b{display:block;font-size:10px;color:var(--fg3);text-transform:uppercase;letter-spacing:.06em}.policy-tile strong{display:block;font-size:24px}
        .policy-risk{list-style:none;padding:0;margin:0}.policy-risk li{display:grid;grid-template-columns:10px minmax(0,1fr) auto;gap:12px;padding:12px 18px;border-top:1px solid var(--line);align-items:start}.policy-risk li:first-child{border-top:0}.policy-sev{width:10px;height:10px;border-radius:50%;margin-top:6px;background:var(--bad)}.policy-sev.warn{background:var(--warn)}.policy-sev.ok{background:var(--ok)}
        .policy-savebar{position:fixed;left:0;right:0;bottom:0;z-index:40;display:none;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap;padding:12px 16px;background:var(--surface);border-top:1px solid var(--line2);box-shadow:0 -8px 24px #0002}.policy-savebar.show{display:flex}.policy-savebar input{width:min(420px,100%);padding:8px 10px;border:1px solid var(--line2);border-radius:8px;background:var(--bg)}
        .policy-empty{padding:32px;text-align:center;color:var(--fg3)}.policy-old{color:var(--bad);text-decoration:line-through}.policy-new{color:var(--ok);font-weight:700}
        @media(max-width:760px){.policy-row{grid-template-columns:minmax(0,1fr)}}
        @media(prefers-color-scheme:dark){.policy-page:not([data-theme=light]){--bg:#0f161b;--surface:#161f26;--surface2:#1d2830;--line:#26333c;--line2:#364652;--fg:#e6edf1;--fg2:#a7b4be;--fg3:#7d8b96;--accent:#4fb8d6;--accent-soft:#12323d}}
        .policy-page[data-theme=dark]{--bg:#0f161b;--surface:#161f26;--surface2:#1d2830;--line:#26333c;--line2:#364652;--fg:#e6edf1;--fg2:#a7b4be;--fg3:#7d8b96;--accent:#4fb8d6;--accent-soft:#12323d}
    </style>

    <div class="policy-page" id="policyPage">
        <div class="policy-top">
            <div class="policy-brand">WiFi Billing <span>/ Platform admin / Policies</span></div>
            <div class="policy-search"><input type="search" id="policySearch" placeholder="Search policies, e.g. withdrawal" aria-label="Search policies"></div>
            <div class="policy-actions">
                <a class="policy-btn" href="{{ route('platform.configuration.control') }}">Control settings</a>
                <a class="policy-btn" href="{{ route('platform.policies.export') }}">Export JSON</a>
                <button class="policy-btn" type="button" id="policyTheme">Theme: auto</button>
            </div>
        </div>

        <div class="policy-content">
            @if(session('status'))<div class="policy-banner">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="policy-banner bad">{{ $errors->first() }}</div>@endif
            <div class="policy-head">
                <div><h1>Platform policies</h1><p>Runtime rules stored in the database. Changes apply immediately and are audited with the reason provided.</p></div>
            </div>
            <nav class="policy-rail" id="policyRail" aria-label="Policy families">
                @foreach(['overview'=>'Overview','lifecycle'=>'Tenant lifecycle','approvals'=>'Approvals','security'=>'Security','wallet'=>'Wallet','provisioning'=>'Provisioning','vouchers'=>'Vouchers','scheduling'=>'Scheduling','history'=>'History'] as $id => $label)
                    <a href="#{{ $id }}" data-tab="{{ $id }}">{{ $label }}@if($id === 'overview')<span class="policy-dot" id="policyRiskDot" @if(!$riskCount) hidden @endif>{{ $riskCount }}</span>@endif</a>
                @endforeach
            </nav>

            <form id="policyForm" method="POST" action="{{ route('platform.policies.update') }}">
                @csrf
                @method('PUT')
                <div id="policyMain">
                    <section class="policy-panel" data-panel="overview">
                        <div class="policy-tiles">
                            <div class="policy-tile"><b>Policies</b><strong>{{ $policies->count() }}</strong></div>
                            <div class="policy-tile"><b>Not default</b><strong>{{ $policies->filter(fn ($p) => array_key_exists($p->key, $defaults) && $p->value !== $defaults[$p->key])->count() }}</strong></div>
                            <div class="policy-tile"><b>Critical risks</b><strong id="policyRiskCount" style="color:{{ $riskCount ? 'var(--bad)' : 'var(--ok)' }}">{{ $riskCount }}</strong></div>
                            <div class="policy-tile"><b>Categories</b><strong>{{ $policies->pluck('category')->unique()->count() }}</strong></div>
                        </div>
                        <section class="policy-card">
                            <header><div><h2>Risk check</h2><p>Critical money and account safeguards.</p></div></header>
                            <ul class="policy-risk">
                                <li data-risk-item="platform_admin_mfa" @if(!empty($values['security.platform_admin_mfa'])) hidden @endif><span class="policy-sev"></span><div><b>Platform admins can sign in without 2FA</b><div class="policy-help">Turn platform-admin MFA back on.</div></div><a href="#security">Review</a></li>
                                <li data-risk-item="step_up" @if(!empty($values['security.step_up_enabled'])) hidden @endif><span class="policy-sev warn"></span><div><b>No step-up check on sensitive actions</b><div class="policy-help">A stolen session could approve money movements without a fresh verification.</div></div><a href="#security">Review</a></li>
                                <li data-risk-item="adjustment_approval" @if(!empty($values['approval.wallet_adjustment.enabled']) && ($values['approval.wallet_adjustment.mode'] ?? '') !== 'never') hidden @endif><span class="policy-sev"></span><div><b>Wallet adjustments need no approval</b><div class="policy-help">One admin can change a balance without review.</div></div><a href="#approvals">Review</a></li>
                                <li data-risk-item="none" @if($riskCount) hidden @endif><span class="policy-sev ok"></span><div><b>No critical risks detected</b><div class="policy-help">This check updates when you change the settings below.</div></div><span></span></li>
                            </ul>
                        </section>
                        <section class="policy-card">
                            <header><div><h2>Differences from defaults</h2><p>Policy values that differ from the seeded default.</p></div></header>
                            @php $different = $policies->filter(fn ($p) => array_key_exists($p->key, $defaults) && $p->value !== $defaults[$p->key]); @endphp
                            @if($different->isEmpty())<div class="policy-empty">No saved policies differ from their defaults.</div>
                            @else<div class="policy-table-wrap"><table class="policy-table"><thead><tr><th>Policy</th><th>Default</th><th>Current</th></tr></thead><tbody>
                                @foreach($different as $policy)
                                    <tr><td><span class="policy-key">{{ $policy->key }}</span></td><td>{{ is_bool($defaults[$policy->key]) ? ($defaults[$policy->key] ? 'Allowed' : 'Blocked') : $defaults[$policy->key] }}</td><td><b>{{ is_bool($policy->value) ? ($policy->value ? 'Allowed' : 'Blocked') : $policy->value }}</b></td></tr>
                                @endforeach
                            </tbody></table></div>@endif
                        </section>
                    </section>

                    <section class="policy-panel" data-panel="lifecycle" hidden>
                        <div class="policy-banner">A tenant can be in several states at once. An action is blocked when any state blocks it. A dash means no policy exists for that pair.</div>
                        <section class="policy-card">
                            <header><div><h2>What each tenant state may do</h2><p>Toggle an action to allow or block it for tenants in that state.</p></div></header>
                            <div class="policy-table-wrap"><table class="policy-table"><thead><tr><th>Action</th>@foreach($catalog['lifecycle']['states'] as $state => $label)<th>{{ $label }}</th>@endforeach</tr></thead><tbody>
                                @foreach($catalog['lifecycle']['actions'] as $action => $label)
                                    <tr><td><b>{{ $label }}</b><br><span class="policy-key">{{ $action }}</span></td>
                                        @foreach($catalog['lifecycle']['states'] as $state => $stateLabel)
                                            @php $key = "lifecycle.{$state}.{$action}"; $policy = $byKey->get($key); @endphp
                                            <td>
                                                @if($policy)
                                                    <label class="policy-toggle"><input type="hidden" name="policies[{{ $key }}]" value="0"><input class="policy-switch" type="checkbox" name="policies[{{ $key }}]" value="1" @checked($policy->value) aria-label="{{ $stateLabel }}: {{ $label }}"><span>{{ $policy->value ? 'Allowed' : 'Blocked' }}</span></label>
                                                @else
                                                    <span title="No policy: always allowed">—</span>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody></table></div>
                        </section>
                        <section class="policy-card">
                            <header><div><h2>Try it on a tenant</h2><p>Pick states to see the current unsaved lifecycle policy outcome.</p></div></header>
                            <div class="policy-row"><div>
                                <b>Tenant is</b>
                                <div style="display:grid;gap:6px;margin-top:10px">
                                    @foreach($catalog['lifecycle']['states'] as $state => $label)
                                        <label><input type="checkbox" data-sim-state="{{ $state }}" @checked(in_array($state, ['suspended','past_due'], true))> {{ $label }}</label>
                                    @endforeach
                                </div>
                            </div><div id="lifecycleResults" style="display:grid;gap:8px"></div></div>
                        </section>
                    </section>

                    @foreach($familyMap as $family => [$title, $description, $category])
                        <section class="policy-panel" data-panel="{{ $family }}" hidden>
                            @if($family === 'scheduling')<div class="policy-banner warn">The scheduler reads these values when it starts. Restart <span class="policy-key">schedule:work</span> after saving.</div>@endif
                            @php
                                $items = $policies->where('category', $category);
                            @endphp
                            <section class="policy-card">
                                <header><div><h2>{{ $title }}</h2><p>{{ $description }}</p></div></header>
                                @forelse($items as $policy)
                                    @php
                                        $field = $catalog['fields'][$policy->key] ?? [];
                                        $label = $field['label'] ?? $policy->description ?? $policy->key;
                                        $unit = $field['unit'] ?? '';
                                        $default = $defaults[$policy->key] ?? null;
                                    @endphp
                                    <div class="policy-row" data-policy-row data-search="{{ strtolower($label.' '.$policy->key.' '.$policy->description) }}">
                                        <div><div class="policy-label">{{ $label }} <span class="policy-key">{{ $policy->key }}</span></div>
                                            <div class="policy-help">{{ $policy->description }}</div>
                                            @if(array_key_exists($policy->key, $defaults))
                                                <div class="policy-default">Default: {{ is_bool($default) ? ($default ? 'Allowed' : 'Blocked') : $default }}
                                                    @if($policy->value !== $default)<button type="button" class="policy-reset" data-key="{{ $policy->key }}" data-value="{{ is_bool($default) ? (int) $default : $default }}">Restore default</button>@endif
                                                </div>
                                            @endif
                                        </div>
                                        <div>
                                            @if(is_bool($policy->value))
                                                <label class="policy-toggle"><input type="hidden" name="policies[{{ $policy->key }}]" value="0"><input type="checkbox" name="policies[{{ $policy->key }}]" value="1" @checked($policy->value) aria-label="{{ $label }}"><span>{{ $policy->value ? 'On' : 'Off' }}</span></label>
                                            @elseif(isset($field['options']))
                                                <select class="policy-input" name="policies[{{ $policy->key }}]" aria-label="{{ $label }}">
                                                    @foreach($field['options'] as $option)<option value="{{ $option }}" @selected((string) $policy->value === (string) $option)>{{ $option }}</option>@endforeach
                                                </select>
                                            @elseif(is_numeric($policy->value))
                                                <div class="control-unit"><input class="policy-input" type="number" name="policies[{{ $policy->key }}]" value="{{ $policy->value }}" @isset($field['min']) min="{{ $field['min'] }}" @endisset @isset($field['max']) max="{{ $field['max'] }}" @endisset step="{{ $field['step'] ?? 1 }}" aria-label="{{ $label }}">@if($unit)<span>{{ $unit }}</span>@endif</div>
                                            @else
                                                <input class="policy-input" type="text" name="policies[{{ $policy->key }}]" value="{{ $policy->value }}" aria-label="{{ $label }}">
                                            @endif
                                        </div>
                                    </div>
                                @empty
                                    <div class="policy-empty">No policies are configured in this family.</div>
                                @endforelse
                            </section>
                        </section>
                    @endforeach

                    <section class="policy-panel" data-panel="history" hidden>
                        <div class="policy-banner">Every update is stored in the audit log with the actor, changed keys, before/after values and required reason.</div>
                        <section class="policy-card">
                            <header><div><h2>Change history</h2><p>Who changed which policy, when, and why.</p></div></header>
                            @if($history->isEmpty())<div class="policy-empty">No policy changes have been recorded.</div>
                            @else<div class="policy-table-wrap"><table class="policy-table"><thead><tr><th>When</th><th>Admin</th><th>Policy changes</th><th>Reason</th></tr></thead><tbody>
                                @foreach($history as $entry)
                                    <tr><td>{{ $entry->created_at->format('Y-m-d H:i') }}</td><td>{{ $entry->user?->name ?? 'System' }}</td><td class="wrap">
                                        @foreach(($entry->meta['changes'] ?? []) as $key => $change)
                                            <div><span class="policy-key">{{ $key }}</span> <span class="policy-old">{{ is_bool($change['old'] ?? null) ? (($change['old'] ?? false) ? 'on' : 'off') : ($change['old'] ?? '-') }}</span> → <span class="policy-new">{{ is_bool($change['new'] ?? null) ? (($change['new'] ?? false) ? 'on' : 'off') : ($change['new'] ?? '-') }}</span></div>
                                        @endforeach
                                        @if(empty($entry->meta['changes'])){{ implode(', ', $entry->meta['keys'] ?? []) }}@endif
                                    </td><td>{{ $entry->meta['reason'] ?? '—' }}</td></tr>
                                @endforeach
                            </tbody></table></div>@endif
                        </section>
                    </section>
                </div>
            </form>

            <div class="policy-savebar" id="policySaveBar" role="region" aria-label="Unsaved policy changes">
                <b id="policyChangeCount">0 unsaved changes</b>
                <input id="policyReason" form="policyForm" type="text" name="reason" placeholder="Reason for this change (required)" minlength="5" maxlength="200" required aria-label="Reason for change">
                <button class="policy-btn" type="button" id="policyDiscard">Discard</button>
                <button class="policy-btn primary" type="submit" form="policyForm" id="policySave" disabled>Save policies</button>
            </div>
        </div>
    </div>

    <script>
        (() => {
            const page = document.getElementById('policyPage');
            const form = document.getElementById('policyForm');
            const bar = document.getElementById('policySaveBar');
            const count = document.getElementById('policyChangeCount');
            const reason = document.getElementById('policyReason');
            const save = document.getElementById('policySave');
            const q = document.getElementById('policySearch');
            const keys = new Map();
            form.querySelectorAll('[name^="policies["]').forEach(input => {
                if (input.type === 'hidden' && form.querySelector(`[name="${CSS.escape(input.name)}"][type="checkbox"]`)) return;
                keys.set(input.name, input.type === 'checkbox' ? (input.checked ? input.value : '0') : input.value);
            });
            const changed = () => {
                const unique = new Set();
                form.querySelectorAll('[name^="policies["]').forEach(input => {
                    if (input.type === 'hidden' && form.querySelector(`[name="${CSS.escape(input.name)}"][type="checkbox"]`)) return;
                    const hidden = input.closest('label')?.querySelector('input[type=hidden]');
                    const value = input.type === 'checkbox' ? (input.checked ? input.value : (hidden?.value ?? '0')) : input.value;
                    const initial = keys.get(input.name);
                    if (value !== initial) unique.add(input.name);
                });
                document.querySelectorAll('.policy-row').forEach(row => {
                    row.classList.toggle('changed', Array.from(row.querySelectorAll('[name^="policies["]')).some(input => unique.has(input.name)));
                });
                bar.classList.toggle('show', unique.size > 0);
                count.textContent = unique.size + ' unsaved change' + (unique.size === 1 ? '' : 's');
                save.disabled = unique.size === 0 || reason.value.trim().length < 5;
                renderRisk();
            };
            const policyCheckbox = key => Array.from(form.querySelectorAll('input[type="checkbox"][name^="policies["]'))
                .find(input => input.name === `policies[${key}]`);
            const renderRisk = () => {
                const adminMfa = policyCheckbox('security.platform_admin_mfa')?.checked ?? true;
                const stepUp = policyCheckbox('security.step_up_enabled')?.checked ?? true;
                const adjustmentApproval = policyCheckbox('approval.wallet_adjustment.enabled')?.checked ?? true;
                const approvalMode = Array.from(form.querySelectorAll('select[name^="policies["]'))
                    .find(input => input.name === 'policies[approval.wallet_adjustment.mode]')?.value;
                const risks = {
                    platform_admin_mfa: !adminMfa,
                    step_up: !stepUp,
                    adjustment_approval: !adjustmentApproval || approvalMode === 'never',
                };
                const total = Object.values(risks).filter(Boolean).length;
                document.querySelectorAll('[data-risk-item]').forEach(item => {
                    item.hidden = item.dataset.risk === 'none' ? total > 0 : !risks[item.dataset.risk];
                });
                document.getElementById('policyRiskCount').textContent = total;
                const dot = document.getElementById('policyRiskDot');
                dot.textContent = total;
                dot.hidden = total === 0;
                document.getElementById('policyRiskCount').style.color = total ? 'var(--bad)' : 'var(--ok)';
            };
            const currentTab = () => location.hash.slice(1) || 'overview';
            const showTab = () => {
                const tab = document.querySelector(`[data-tab="${CSS.escape(currentTab())}"]`) ? currentTab() : 'overview';
                document.querySelectorAll('[data-tab]').forEach(link => link.classList.toggle('active', link.dataset.tab === tab));
                document.querySelectorAll('[data-panel]').forEach(panel => panel.hidden = panel.dataset.panel !== tab);
            };
            form.addEventListener('input', changed);
            form.addEventListener('change', event => {
                const row = event.target.closest('.policy-row');
                if (row) row.classList.add('changed');
                changed();
            });
            reason.addEventListener('input', changed);
            document.getElementById('policyDiscard').addEventListener('click', () => location.reload());
            window.addEventListener('beforeunload', event => {
                if (bar.classList.contains('show')) { event.preventDefault(); event.returnValue = ''; }
            });
            window.addEventListener('hashchange', showTab);
            q.addEventListener('input', () => {
                const value = q.value.trim().toLowerCase();
                document.querySelectorAll('[data-policy-row]').forEach(row => row.hidden = value !== '' && !row.dataset.search.includes(value));
            });
            document.querySelectorAll('.policy-reset').forEach(button => button.addEventListener('click', () => {
                const name = `policies[${button.dataset.key}]`;
                const control = form.querySelector(`[name="${CSS.escape(name)}"]`);
                if (!control) return;
                if (control.type === 'checkbox') control.checked = button.dataset.value === '1';
                else control.value = button.dataset.value;
                changed();
            }));
            document.querySelectorAll('[data-sim-state]').forEach(input => input.addEventListener('change', renderLifecycle));
            form.addEventListener('change', event => {
                if (event.target.name?.startsWith('policies[lifecycle.')) renderLifecycle();
            });
            function renderLifecycle() {
                const selected = Array.from(document.querySelectorAll('[data-sim-state]:checked')).map(item => item.dataset.simState);
                const actions = @json($catalog['lifecycle']['actions']);
                const output = document.getElementById('lifecycleResults');
                output.innerHTML = '';
                Object.entries(actions).forEach(([action, label]) => {
                    const blocked = selected.filter(state => {
                        const name = `policies[lifecycle.${state}.${action}]`;
                        const input = form.querySelector(`[name="${CSS.escape(name)}"][type=checkbox]`);
                        return input && !input.checked;
                    });
                    const item = document.createElement('div');
                    item.className = 'policy-card';
                    item.style.padding = '10px 12px';
                    item.style.margin = '0';
                    item.innerHTML = `<b>${label}</b><div class="policy-help">${blocked.length ? 'Blocked by ' + blocked.join(', ') : 'Allowed'}</div>`;
                    output.appendChild(item);
                });
            }
            const modes = ['auto', 'light', 'dark'];
            let mode = 0;
            document.getElementById('policyTheme').addEventListener('click', () => {
                mode = (mode + 1) % modes.length;
                if (modes[mode] === 'auto') page.removeAttribute('data-theme');
                else page.setAttribute('data-theme', modes[mode]);
                document.getElementById('policyTheme').textContent = 'Theme: ' + modes[mode];
            });
            showTab();
            renderLifecycle();
            renderRisk();
        })();
    </script>
</x-app-layout>
