import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

class Target {
    constructor() { this.listeners = {}; this.dataset = {}; this.disabled = false; this.value = ''; this.type = ''; this.name = ''; this.style = {}; this.attributes = {}; this.textContent = ''; this.innerHTML = ''; this.classList = { values: new Set(), toggle: (s, on) => on ? this.classList.values.add(s) : this.classList.values.delete(s), contains: s => this.classList.values.has(s), add: s => this.classList.values.add(s) }; }
    addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); }
    dispatchEvent(event) { event.target ||= this; for (const fn of this.listeners[event.type] || []) { fn(event); if (event.stopped) break; } return !event.defaultPrevented; }
    setAttribute(name, value) { this.attributes[name] = value; }
    removeAttribute(name) { delete this.attributes[name]; }
    hasAttribute(name) { if (name === 'data-ui-original-html') return Object.hasOwn(this.dataset, 'uiOriginalHtml'); return Object.hasOwn(this.attributes, name); }
    closest() { return null; }
    appendChild(child) { child.parent = this; child.isConnected = true; (this.children ||= []).push(child); }
    remove() { if (this.parent) this.parent.children = this.parent.children.filter(x => x !== this); }
    replaceChildren() { this.children = []; }
    insertAdjacentHTML() {}
}
class Form extends Target {
    constructor() { super(); this.method = 'post'; this.inputs = []; this.elements = []; this.children = []; }
    querySelectorAll(selector) {
        if (selector === '[data-setting-input]') return this.inputs;
        if (selector === '[name^="policies["]') return this.inputs;
        if (selector === '[data-ui-submitter-copy]') return this.children.filter(x => x.dataset.uiSubmitterCopy);
        if (selector === 'input[type="checkbox"][name^="policies["]') return this.inputs.filter(x => x.type === 'checkbox');
        if (selector === 'select[name^="policies["]') return this.inputs.filter(x => x.type === 'select-one');
        return [];
    }
    querySelector(selector) {
        if (selector === 'input[name="_idempotency_key"]') return this.children.find(x => x.name === '_idempotency_key') || null;
        const m = selector.match(/\[name="(.*?)"\]/); if (!m) return null;
        return this.inputs.find(x => x.name === m[1] && (!selector.includes('checkbox') || x.type === 'checkbox')) || null;
    }
}
class Button extends Target {}
class Input extends Target {}
const evt = type => ({ type, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, stopImmediatePropagation() { this.stopped = true; } });
const flush = () => new Promise(resolve => setImmediate(resolve));

function fixture(kind, old = {}) {
    const form = new Form(), save = new Button(), reason = new Input(), window = new Target(), doc = new Target();
    const store = old.store || new Map();
    const storageCalls = [];
    const sessionStorage = {
        get length() { if (old.blockStorage) throw Error('storage blocked'); return store.size; },
        key: index => [...store.keys()][index] ?? null,
        getItem: key => { storageCalls.push(['get', key]); return store.get(key) ?? null; },
        setItem: (key, value) => { storageCalls.push(['set', key, value]); store.set(key, value); },
        removeItem: key => { storageCalls.push(['remove', key]); store.delete(key); },
    };
    if (!old.noFormDataEvent) window.FormDataEvent = class {};
    const input = new Input(); input.name = kind === 'policy' ? 'policies[a.b]' : 'settings[a.b]'; input.value = 'old'; input.type = 'text';
    const stable = new Input(); stable.name = 'policies[stable]'; stable.value = 'same'; stable.type = 'text';
    const secret = new Input(); secret.type = 'password'; secret.name = 'settings[sms.api_key]'; secret.value = '';
    form.inputs = kind === 'policy' ? [input, stable] : [input, ...(old.includeSecret ? [secret] : [])]; form.elements = [...form.inputs, save];
    const elements = {};
    for (const id of ['controlPage','saveBar','settingSearch','discardChanges','themeToggle','policyPage','policySaveBar','policySaveError','policyChangeCount','policySearch','policyDiscard','policyRiskCount','policyRiskDot','lifecycleResults','policyTheme']) elements[id] = new Target();
    Object.assign(elements, kind === 'policy' ? { policyForm: form, policySave: save, policyReason: reason } : { settingsForm: form, saveChanges: save, auditReason: reason });
    doc.getElementById = id => elements[id] || null;
    doc.querySelectorAll = selector => selector === 'form[data-ui-submitting="true"]' ? (form.dataset.uiSubmitting === 'true' ? [form] : []) : [];
    doc.querySelector = () => null;
    doc.createElement = tag => tag === 'input' ? new Input() : new Target();
    const sourceFile = kind === 'policy' ? 'policy-center' : 'control-settings';
    let blade = readFileSync(new URL(`../../resources/views/platform/configuration/${sourceFile}.blade.php`, import.meta.url), 'utf8');
    let script = blade.match(/<script>\s*([\s\S]*?)<\/script>/)[1];
    script = script.replace(/@php[\s\S]*?@endphp/g, '')
      .replace(/const oldGroups = .*;/, `const oldGroups = ${JSON.stringify(old.groups || {settings: {}, policies: {}})};`)
      .replace(/const oldPolicies = .*;/, `const oldPolicies = ${JSON.stringify(old.policies || {})};`)
      .replace(/reason\.value = @js\(.*\);/, `reason.value = ${JSON.stringify(old.reason || '')};`)
      .replace('@js($errors->any())', old.error ? 'true' : 'false')
      .replace(/@json\(\$catalog\['lifecycle'\]\['actions'\]\)/, '{}');
    const context = vm.createContext({ document: doc, window, sessionStorage, HTMLFormElement: Form, HTMLButtonElement: Button, HTMLInputElement: Input, CustomEvent: class { constructor(type) { this.type = type; } }, Event: class { constructor(type) { this.type = type; } }, queueMicrotask, crypto: { randomUUID: () => 'key' }, CSS: { escape: x => x }, location: { hash: '', pathname: '/settings', search: '?section=general', reload() {} }, console });
    vm.runInContext(script, context);
    vm.runInContext(readFileSync(new URL('../../resources/js/form-guard.js', import.meta.url), 'utf8'), context);
    function submit(cancel = false) { const e = evt('submit'); e.target = form; e.submitter = save; doc.dispatchEvent(e); if (!e.stopped) form.dispatchEvent(e); if (cancel) e.preventDefault(); return e; }
    function unload() { const e = evt('beforeunload'); window.dispatchEvent(e); return e.defaultPrevented; }
    return {form, save, reason, input, stable, secret, window, doc, elements, submit, unload, storageCalls};
}

for (const kind of ['control', 'policy']) {
    test(`${kind}: dirty navigation, save, cancellation, duplicate lock and back recovery`, async () => {
        const f = fixture(kind); f.input.value = 'new'; f.form.dispatchEvent(evt('input')); f.reason.value = 'valid reason'; f.reason.dispatchEvent(evt('input'));
        assert.equal(f.unload(), true); assert.equal(f.save.disabled, false);
        const cancelled = f.submit(true); await flush(); assert.equal(cancelled.defaultPrevented, true); assert.equal(f.save.disabled, false); assert.equal(f.unload(), true);
        f.submit(); assert.equal(f.save.disabled, true); assert.equal(f.unload(), true); // A competing navigation can be first.
        assert.equal(f.unload(), true); // Save and later navigation both warn while dirty.
        f.reason.dispatchEvent(evt('input')); assert.equal(f.save.disabled, true);
        const duplicate = f.submit(); assert.equal(duplicate.defaultPrevented, true); await flush(); assert.equal(f.save.disabled, true);
        f.window.dispatchEvent(evt('pageshow')); assert.equal(f.save.disabled, false); assert.equal(f.unload(), true);
    });
    test(`${kind}: validation displays current values without replaying stale flashed input`, () => {
        const f = fixture(kind, {error: true, policies: {'a.b': 'rejected'}, groups: {settings: {'a.b':'rejected'}, policies: {}}, reason: 'sensitive reason'});
        assert.equal(f.input.value, 'old'); assert.equal(f.reason.value, ''); assert.equal(f.save.disabled, true); assert.equal(f.unload(), false);
        const blade = readFileSync(new URL(`../../resources/views/platform/configuration/${kind === 'policy' ? 'policy-center' : 'control-settings'}.blade.php`, import.meta.url), 'utf8');
        assert.match(blade, /submitted changes and reason are not restored/);
        assert.doesNotMatch(blade, /old\(['"](?:reason|settings|policies)/);
    });
}

test('control never writes or reads drafts, and cleans legacy entries without touching unrelated storage', async () => {
    const store = new Map([
        ['control-settings-draft:/settings?section=sms', JSON.stringify({values: {'settings[account_id]': 'old-id'}, reason: 'private'})],
        ['control-settings-draft:/settings?section=email', 'legacy'], ['unrelated', 'keep'],
    ]);
    const first = fixture('control', {store, includeSecret: true});
    assert.deepEqual([...store.entries()], [['unrelated', 'keep']]);
    assert.equal(first.input.value, 'old'); assert.equal(first.reason.value, '');
    first.secret.value = 'super-secret'; first.input.value = 'account-id';
    first.form.dispatchEvent(evt('input')); first.reason.value = 'private reason'; first.reason.dispatchEvent(evt('input'));
    first.submit(); await flush(); first.unload(); first.window.dispatchEvent(evt('pageshow'));
    assert.deepEqual([...store.entries()], [['unrelated', 'keep']]);
    assert.equal(first.storageCalls.some(([op]) => op === 'get' || op === 'set'), false);
    const second = fixture('control', {store, error: true});
    assert.equal(second.input.value, 'old'); assert.equal(second.reason.value, ''); assert.equal(second.unload(), false);
});

test('control continues safely when storage cleanup is blocked', () => {
    const f = fixture('control', {blockStorage: true});
    f.input.value = 'new'; f.form.dispatchEvent(evt('input')); f.reason.value = 'valid reason'; f.reason.dispatchEvent(evt('input'));
    assert.equal(f.save.disabled, false); f.submit(); assert.equal(f.unload(), true);
});

test('policy submission fails closed without formdata support', async () => {
    const f = fixture('policy', {noFormDataEvent: true}); f.input.value = 'new'; f.form.dispatchEvent(evt('input')); f.reason.value = 'valid reason'; f.reason.dispatchEvent(evt('input'));
    const e = f.submit(); await flush(); assert.equal(e.defaultPrevented, true); assert.equal(f.elements.policySaveError.hidden, false); assert.equal(f.save.disabled, false); assert.equal(f.unload(), true);
});

test('policy serialization removes unchanged entries without disabling controls', () => {
    const f = fixture('policy'); f.input.value = 'new'; f.form.dispatchEvent(evt('input'));
    const deleted = []; const e = evt('formdata'); e.formData = { delete: name => deleted.push(name) }; f.form.dispatchEvent(e);
    assert.deepEqual(deleted, ['policies[stable]']); assert.equal(f.input.disabled, false); assert.equal(f.stable.disabled, false);
});

test('guard restores an associated external button and submitter copy on cancellation', async () => {
    const f = fixture('control'); f.input.value = 'new'; f.form.dispatchEvent(evt('input')); f.reason.value = 'reason'; f.reason.dispatchEvent(evt('input'));
    f.save.name = 'action'; f.save.value = 'save'; f.save.innerHTML = 'Save changes';
    f.submit(true); await flush(); assert.equal(f.save.disabled, false); assert.equal(f.save.innerHTML, 'Save changes'); assert.equal(f.form.children.filter(x => x.dataset.uiSubmitterCopy).length, 0);
});
