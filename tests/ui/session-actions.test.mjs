import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const view = readFileSync(new URL('../../resources/views/operator/sessions/index.blade.php', import.meta.url), 'utf8');
const deferred = () => { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return { promise, resolve, reject }; };
const tick = () => new Promise(resolve => setImmediate(resolve));

for (const [method, marker, url, pendingField] of [
    ['revokeAccess', 'Void voucher and disconnect', 'disconnectUrl', 'pendingRevocation'],
    ['banDevice', 'Ban the router-verified MAC without voiding the voucher', 'banUrl', 'pendingDeviceBan'],
]) {
    function fixture() {
        const part = view.slice(view.indexOf(marker));
        const data = part.match(/x-data="([\s\S]*?)"\s*x-effect="([^"]*)"/);
        assert.ok(data, `Missing ${method} Alpine component`);
        let confirmation = deferred(), request = deferred(); const calls = [], toasts = [];
        const context = vm.createContext({ session: null, selectionVersion: 0, window: {
            confirmWithReason: (...args) => { calls.push(['confirm', ...args]); return confirmation.promise; },
            apiFetch: (...args) => { calls.push(['fetch', ...args]); return request.promise; },
            toast: { error: x => toasts.push(['error', x]), info: x => toasts.push(['info', x]), warning: x => toasts.push(['warning', x]) },
        }});
        const state = vm.runInContext(`(${data[1]})`, context); context.state = state;
        const select = session => { context.selectionVersion++; context.session = session; vm.runInContext(`with(state) { ${data[2]} }`, context); };
        const session = id => ({ id, voucher: `voucher-${id}`, disconnectUrl: `/disconnect/${id}`, banUrl: `/ban/${id}`, siteAgentOnline: true });
        return { state, select, session, calls, toasts, get confirmation() { return confirmation; }, get request() { return request; }, renew() { confirmation = deferred(); request = deferred(); } };
    }
    test(`${method}: selection ABA invalidates confirmation and lock survives selection`, async () => {
        const f = fixture(), a = f.session(1), b = f.session(2); f.select(a);
        const first = f.state[method](); f.select(b); f.select(a);
        assert.equal(f.state.loading, true); await f.state[method]();
        assert.equal(f.calls.filter(x => x[0] === 'confirm').length, 1);
        f.confirmation.resolve('reason'); await first;
        assert.equal(f.calls.filter(x => x[0] === 'fetch').length, 0); assert.equal(f.state.loading, false);
        f.renew(); const second = f.state[method](); f.confirmation.resolve(null); await second;
        assert.equal(f.calls.filter(x => x[0] === 'fetch').length, 0);
    });
    test(`${method}: stale request result and rejection do not mutate new selection or toast`, async () => {
        for (const reject of [false, true]) {
            const f = fixture(), a = f.session(1), b = f.session(2); f.select(a);
            const first = f.state[method](); f.confirmation.resolve('reason'); await tick();
            assert.equal(f.calls.find(x => x[0] === 'fetch')[1], a[url]);
            f.select(b); f.select(a); assert.equal(f.state.loading, true); await f.state[method]();
            if (reject) f.request.reject(new Error('stale')); else f.request.resolve({ success: true, message: 'queued', site_agent_online: true });
            await first; assert.equal(f.state.loading, false); assert.equal(f.state.pending, false); assert.equal(a[pendingField], undefined); assert.deepEqual(f.toasts, []);
        }
    });
    test(`${method}: valid confirmation makes one request and updates current session`, async () => {
        const f = fixture(), a = f.session(1); f.select(a); const first = f.state[method]();
        await f.state[method](); f.confirmation.resolve('reason'); await tick();
        const fetches = f.calls.filter(x => x[0] === 'fetch'); assert.equal(fetches.length, 1); assert.equal(fetches[0][1], a[url]);
        assert.equal(JSON.parse(fetches[0][2].body).reason, 'reason');
        f.request.resolve({ success: true, message: 'queued', site_agent_online: false }); await first;
        assert.equal(f.state.pending, true); assert.equal(a[pendingField], true); assert.equal(a.disconnecting, true); assert.equal(f.state.loading, false);
        assert.deepEqual(f.toasts, [['warning', 'queued']]);
    });
}
