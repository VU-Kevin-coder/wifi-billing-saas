import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { mobileSidebar } from '../../resources/js/mobile-sidebar.js';

const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const tick = () => new Promise(resolve => setImmediate(resolve));

function drawerFixture() {
    const focus = [];
    const element = (name, visible = true) => ({
        isConnected: true, getClientRects: () => visible ? [{}] : [], closest: () => null,
        focus: () => { focus.push(name); globalThis.document.activeElement = elements[name]; },
    });
    const elements = { close: element('close'), link: element('link'), trigger: element('trigger'), main: element('main') };
    const state = mobileSidebar();
    state.$nextTick = callback => callback();
    state.$refs = { sidebarClose: elements.close, mainContent: elements.main,
        sidebarDialog: { querySelectorAll: () => [elements.close, elements.link], contains: target => [elements.close, elements.link].includes(target) } };
    globalThis.document = { activeElement: null };
    return { state, elements, focus };
}

test('drawer opens focused, traps Tab both ways, contains focus and restores opener', () => {
    const { state, elements, focus } = drawerFixture();
    state.sidebarCollapsed = true; state.openSidebar(elements.trigger);
    assert.equal(state.mobileSidebarOpen, true); assert.equal(state.sidebarCollapsed, false); assert.equal(focus.at(-1), 'close');
    const event = shiftKey => ({ key: 'Tab', shiftKey, prevented: false, preventDefault() { this.prevented = true; } });
    let e = event(true); state.trapSidebarTab(e); assert.ok(e.prevented); assert.equal(focus.at(-1), 'link');
    e = event(false); state.trapSidebarTab(e); assert.ok(e.prevented); assert.equal(focus.at(-1), 'close');
    state.keepSidebarFocus({ target: elements.trigger }); assert.equal(focus.at(-1), 'close');
    state.closeSidebar(); assert.equal(state.mobileSidebarOpen, false); assert.equal(focus.at(-1), 'trigger');
});

test('drawer restores to main content when mobile trigger becomes invisible', () => {
    const { state, elements, focus } = drawerFixture(); state.openSidebar(elements.trigger);
    elements.trigger.getClientRects = () => [];
    globalThis.window = { matchMedia: () => ({ matches: true }) };
    state.closeSidebarOnDesktop(); assert.equal(state.mobileSidebarOpen, false); assert.equal(focus.at(-1), 'main');
});

test('drawer markup and router controls expose names and page selection semantics', () => {
    const app = read('resources/views/layouts/app.blade.php'); const bar = read('resources/views/layouts/topbar.blade.php');
    const routers = read('resources/views/operator/routers/index.blade.php');
    assert.match(app, /role="dialog" aria-modal="true" aria-label="Main navigation"/);
    assert.match(app, /@keydown\.escape\.window="closeSidebar\(\)"/);
    assert.match(app, /hidden lg:flex shrink-0/); assert.match(app, /:inert="mobileSidebarOpen \? '' : null"/);
    assert.match(app, /body:not\(\.navigation-enhanced\) \.navigation-fallback \{ display: block; \}/);
    assert.match(app, /<details class="navigation-fallback">/);
    assert.match(app, /x-init="document\.body\.classList\.add\('navigation-enhanced'\)"/);
    assert.match(bar, /aria-label="Open navigation" aria-controls="mobile-navigation"/);
    assert.match(routers, /aria-label="Select all routers on this page"/);
    assert.match(routers, /selected\.length === \{\{ \$routers->count\(\) \}\}/);
    assert.match(routers, /aria-label="Select router \{\{ \$router->name \}\}"/);
});

function paymentFixture(responses) {
    const source = read('resources/views/portal/pending.blade.php');
    const js = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace('@json($checkUrl)', "'/check'").replace('@json($successUrl)', "'/success'");
    const classes = new Set(['hidden']);
    const status = { textContent: '', classList: { add: c => classes.add(c) } };
    const link = { classList: { remove: c => classes.delete(c) } };
    const scheduled = []; const redirects = [];
    const document = { getElementById: id => id === 'status-message' ? status : link, addEventListener() {} };
    const window = { apiFetch: async () => { const result = responses.shift(); if (result instanceof Error) throw result; return result; },
        setTimeout: (fn, delay) => scheduled.push([fn, delay]), location: { assign: url => redirects.push(url) } };
    const context = vm.createContext({ document, window }); vm.runInContext(js, context);
    return { check: () => vm.runInContext('checkPayment()', context), status, classes, scheduled, redirects };
}

test('payment polling recovers from a network error to pending and retries', async () => {
    const f = paymentFixture([new Error('offline'), {status: 'pending'}]);
    await f.check(); assert.match(f.status.textContent, /Unable to check/); assert.equal(f.scheduled[0][1], 5000);
    await f.scheduled.shift()[0](); assert.equal(f.status.textContent, 'Waiting for payment confirmation…'); assert.equal(f.scheduled.length, 1);
});

test('confirmed payment awaiting fulfillment warns against paying again and keeps polling', async () => {
    const f = paymentFixture([{ status: 'fulfillment_pending' }, { status: 'completed', redirect_url: '/done' }]);
    await f.check();
    assert.equal(f.status.textContent, 'Payment confirmed. Access is being prepared. Please do not pay again.');
    assert.deepEqual(f.redirects, []);
    assert.ok(f.classes.has('hidden'), 'return link stays hidden while fulfillment is pending');
    assert.ok(!f.classes.has('failed'), 'fulfillment pending is not a failed payment');
    assert.equal(f.scheduled.length, 1);
    assert.equal(f.scheduled[0][1], 5000);
    await f.scheduled.shift()[0]();
    assert.deepEqual(f.redirects, ['/done']);
    assert.equal(f.scheduled.length, 0);
});

test('payment failure stops polling and exposes return; completion redirects', async () => {
    const failed = paymentFixture([{ status: 'failed', message: 'Declined' }]); await failed.check();
    assert.equal(failed.status.textContent, 'Declined'); assert.ok(failed.classes.has('failed')); assert.ok(!failed.classes.has('hidden')); assert.equal(failed.scheduled.length, 0);
    const done = paymentFixture([{status: 'completed', redirect_url: '/done'}]); await done.check();
    assert.deepEqual(done.redirects, ['/done']); assert.equal(done.scheduled.length, 0);
});

test('voucher live status follows valid, uncancelled submissions and clears on restore', async () => {
    const page = read('resources/views/portal/index.blade.php');
    const script = page.match(/<script>\s*(\(\(\) => \{[\s\S]*?voucher-status[\s\S]*?\}\)\(\);)\s*<\/script>/)[1];
    const handlers = {}, windowHandlers = {}, status = { textContent: '' };
    let valid = true;
    const form = { addEventListener: (name, fn) => { handlers[name] = fn; }, checkValidity: () => valid };
    const document = { getElementById: id => id === 'voucher-form' ? form : status };
    vm.runInNewContext(script, { document, window: { addEventListener: (name, fn) => { windowHandlers[name] = fn; } }, queueMicrotask });
    valid = false; handlers.submit({ defaultPrevented: false }); await tick(); assert.equal(status.textContent, '');
    valid = true; const cancelled = { defaultPrevented: false }; handlers.submit(cancelled); cancelled.defaultPrevented = true; await tick(); assert.equal(status.textContent, '');
    handlers.submit({ defaultPrevented: false }); await tick(); assert.equal(status.textContent, 'Checking voucher…');
    handlers['ui:form-restored'](); assert.equal(status.textContent, '');
    handlers.submit({ defaultPrevented: false }); await tick(); windowHandlers.pageshow(); assert.equal(status.textContent, '');
});

test('voucher UI uses submit feedback without invented workflow stages', () => {
    const page = read('resources/views/portal/index.blade.php');
    assert.match(page, /data-loading-text="Checking voucher…"/); assert.match(page, /data-loading-text="Submitting payment…"/);
    assert.doesNotMatch(page, /voucher-checking|Voucher found, activating|Code received/);
    assert.match(page, /No packages are currently available/);
});
