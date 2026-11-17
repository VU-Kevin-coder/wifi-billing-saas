import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

class Element extends EventTarget {
    constructor(tag) {
        super(); this.tagName = tag.toUpperCase(); this.children = []; this.attributes = {}; this.className = ''; this.textContent = ''; this.open = false; this.isConnected = false;
        this.classList = { add: name => { this.className += ` ${name}`; } };
    }
    setAttribute(name, value) { this.attributes[name] = value; }
    get tabIndex() { return this._tabIndex ?? (['INPUT','BUTTON','TEXTAREA','SELECT'].includes(this.tagName) ? 0 : -1); }
    set tabIndex(value) { this._tabIndex = value; }
    matches(selector) { return selector === ':disabled' && !!this.disabled; }
    closest(selector) {
        for (let node = this; node; node = node.parent) {
            if (selector === 'dialog' && node.tagName === 'DIALOG') return node;
            if (selector === 'dialog[open]' && node.tagName === 'DIALOG' && node.open) return node;
            if (selector === '[hidden], [inert]' && (node.hidden || node.inert)) return node;
        }
        return null;
    }
    getClientRects() { return this.visible === false ? [] : [1]; }
    querySelectorAll(selector) { const result=[]; const visit=n=>{for (const c of n.children) { if (selector === 'dialog[open]' ? c.tagName === 'DIALOG' && c.open : ['INPUT','BUTTON','TEXTAREA','SELECT'].includes(c.tagName) || c._tabIndex !== undefined) result.push(c); visit(c); }}; visit(this); return result; }
    append(...children) { for (const child of children) { child.parent = this; child.isConnected = true; this.children.push(child); } }
    remove() { if (this.parent) this.parent.children = this.parent.children.filter(child => child !== this); this.isConnected = false; }
    focus() { document.activeElement = this; }
    showModal() { this.open = true; }
    close() { this.open = false; this.dispatchEvent(new Event('close')); }
    click() { this.dispatchEvent(new Event('click')); }
}
const body = new Element('body');
body.isConnected = true;
const document = Object.assign(new EventTarget(), { body, activeElement: null, createElement: tag => new Element(tag) });
const submitListeners = [];
const addListener = document.addEventListener.bind(document);
document.addEventListener = (type, listener, options) => type === 'submit' ? submitListeners.push(listener) : addListener(type, listener, options);
class MockForm {
    constructor() { this.dataset = { confirmAction: 'Delete?' }; this.isConnected = true; this.submissions = 0; this.valid = true; }
    requestSubmit(submitter) {
        if (!this.valid) return;
        const event = { target: this, submitter, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, stopImmediatePropagation() {} };
        submitListeners.forEach(listener => listener(event));
        if (!event.defaultPrevented) this.submissions++;
    }
}
const window = new EventTarget();
window.getComputedStyle = () => ({visibility:'visible'});
Object.assign(globalThis, { document, window, HTMLFormElement: MockForm, CustomEvent: class extends Event { constructor(type, options) { super(type); this.detail = options.detail; } } });
globalThis.MutationObserver = class { constructor(fn) { this.fn = fn; } observe() {} disconnect() {} };
vm.runInThisContext(readFileSync(new URL('../../public/js/ui-dialog.js', import.meta.url), 'utf8'));
vm.runInThisContext(readFileSync(new URL('../../resources/js/confirm.js', import.meta.url), 'utf8'));
const modal = () => body.children.find(child => child.tagName === 'DIALOG');
const button = cls => modal().children.find(child => child.className === 'ui-dialog-actions').children.find(child => child.className.includes(cls));
const tick = () => new Promise(resolve => setImmediate(resolve));

test('Tab and Shift-Tab cycle dynamic enabled controls and restore focus', async () => {
    const origin = new Element('button'); body.append(origin); origin.focus();
    const pending = window.Swal.fire({input:'text',showCancelButton:true});
    const dialog = modal(); const input=dialog.children.find(n=>n.tagName==='INPUT');
    const cancel=button('ui-dialog-cancel'), confirm=button('ui-dialog-confirm');
    function tab(shift=false) { const e=new Event('keydown',{cancelable:true}); Object.assign(e,{key:'Tab',shiftKey:shift}); dialog.dispatchEvent(e); assert.ok(e.defaultPrevented); }
    assert.equal(document.activeElement,input);
    tab(true); assert.equal(document.activeElement,confirm);
    tab(); assert.equal(document.activeElement,input);
    const dynamic=new Element('input'); dialog.children.find(n=>n.className==='ui-dialog-actions').append(dynamic);
    tab(true); assert.equal(document.activeElement,dynamic);
    dynamic.disabled=true; tab(true); assert.equal(document.activeElement,confirm);
    confirm.disabled=true; tab(); assert.equal(document.activeElement,input);
    input.disabled=true; cancel.disabled=true; tab(); assert.equal(document.activeElement,dialog);
    cancel.disabled=false; cancel.click(); await pending; assert.equal(document.activeElement,origin);
});

test('cancel, escape, text-only and focus restoration', async () => {
    const origin = new Element('button'); body.append(origin); origin.focus();
    const pending = window.confirmAction('<img src=x>');
    assert.equal(modal().children[1].textContent, '<img src=x>');
    assert.equal(document.activeElement.className, 'ui-dialog-cancel');
    button('ui-dialog-cancel').click();
    assert.equal(await pending, false);
    assert.equal(document.activeElement, origin);
    const escape = window.confirmAction('Confirm?');
    modal().dispatchEvent(new Event('cancel', { cancelable: true }));
    assert.equal(await escape, false);
});

test('validates reason before confirming and serializes dialogs', async () => {
    const pending = window.confirmWithReason('Explain');
    const second = window.Swal.fire({ title: 'Second' });
    button('ui-dialog-confirm').click(); await tick();
    assert.equal(modal().children.find(child => child.className === 'ui-dialog-error').textContent, 'Enter at least 3 characters.');
    modal().children.find(child => child.tagName === 'TEXTAREA').value = '  valid reason  ';
    button('ui-dialog-confirm').click();
    assert.equal(await pending, 'valid reason'); await tick();
    assert.equal(modal().children[0].textContent, 'Second');
    button('ui-dialog-confirm').click();
    assert.equal((await second).isConfirmed, true);
});

test('confirmation forms block duplicates, cancellation and stale validation', async () => {
    const form = new MockForm();
    form.requestSubmit();
    form.requestSubmit();
    assert.equal(form.submissions, 0);
    button('ui-dialog-cancel').click(); await tick();
    assert.equal(form.submissions, 0);
    form.requestSubmit();
    form.valid = false;
    button('ui-dialog-confirm').click(); await tick();
    assert.equal(form.submissions, 0);
    assert.equal(form.dataset.confirmed, undefined);
    form.valid = true;
    form.requestSubmit();
    assert.ok(modal());
    button('ui-dialog-confirm').click(); await tick();
    assert.equal(form.submissions, 1);
});

test('missing modal support fails closed', async () => {
    const original = Element.prototype.showModal;
    Element.prototype.showModal = undefined;
    try { assert.equal(await window.confirmAction('Do not proceed'), false); }
    finally { Element.prototype.showModal = original; }
});


test('cleanup exceptions do not strand confirmation or queued dialog', async () => {
    const origin = new Element('button'); body.append(origin); origin.focus();
    origin.focus = () => { throw new Error('focus failed'); };
    const first = window.confirmAction('First');
    const second = window.confirmAction('Second');
    const dialog = modal(); dialog.close = () => { throw new Error('close failed'); };
    button('ui-dialog-cancel').click();
    assert.equal(await first, false); await tick();
    assert.equal(modal().children[1].textContent, 'Second');
    button('ui-dialog-cancel').click(); assert.equal(await second, false);
    origin.remove(); document.activeElement = null;
});

test('asynchronous completion waits for nested modal removal before advancing queue', async () => {
    let notify, disconnected = false, release;
    globalThis.MutationObserver = class { constructor(fn) { notify = fn; } observe() {} disconnect() { disconnected = true; } };
    try {
        const first = window.Swal.fire({ input:'text', inputValidator: () => new Promise(resolve => { release = resolve; }) });
        const parent = modal();
        const second = window.Swal.fire({ title:'Next' });
        button('ui-dialog-confirm').click();
        const child = new Element('dialog'); parent.append(child); child.showModal(); child.focus();
        release(null); await tick();
        assert.equal(modal(), parent); assert.equal(child.isConnected, true); assert.equal(document.activeElement, child);
        child.remove(); notify();
        assert.equal((await first).isConfirmed, true); await tick();
        assert.equal(disconnected, true); assert.equal(modal().children[0].textContent, 'Next');
        button('ui-dialog-confirm').click(); assert.equal((await second).isConfirmed, true);
    } finally { globalThis.MutationObserver = class { observe() {} disconnect() {} }; }
});

test('removed parent while nested modal is open dismisses rather than confirming', async () => {
    let notify, release;
    globalThis.MutationObserver = class { constructor(fn) { notify = fn; } observe() {} disconnect() {} };
    try {
        const pending = window.Swal.fire({ input:'text', inputValidator: () => new Promise(resolve => { release = resolve; }) });
        const parent = modal(); button('ui-dialog-confirm').click();
        const child = new Element('dialog'); parent.append(child); child.showModal();
        release(null); await tick(); parent.remove(); notify();
        assert.equal((await pending).isConfirmed, false);
    } finally { globalThis.MutationObserver = class { observe() {} disconnect() {} }; }
});

test('dialog construction exceptions fail closed and queue remains usable', async () => {
    const create = document.createElement;
    document.createElement = () => { throw new Error('construction failed'); };
    try { assert.equal(await window.confirmAction('Fails closed'), false); }
    finally { document.createElement = create; }
    const next = window.confirmAction('Next'); await tick();
    button('ui-dialog-cancel').click(); assert.equal(await next, false);
});

test('external removal fails closed and unblocks queue', async () => { let notify; globalThis.MutationObserver = class { constructor(fn){notify=fn} observe(){} disconnect(){} }; try { const first=window.Swal.fire({title:'First'}); const parent=modal(); const second=window.Swal.fire({title:'Second'}); parent.remove(); notify(); assert.equal((await first).isConfirmed,false); await tick(); assert.equal(modal().children[0].textContent,'Second'); button('ui-dialog-confirm').click(); assert.equal((await second).isConfirmed,true); } finally { globalThis.MutationObserver=class {observe(){} disconnect(){}}; } });
test('validator awaiting nested modal does not deadlock', async () => { let nested; const first=window.Swal.fire({input:'text', inputValidator:async()=>{ nested=await window.Swal.fire({title:'Nested'}); return nested.isConfirmed ? null : 'Unavailable'; }}); button('ui-dialog-confirm').click(); await tick(); assert.equal(nested.isDismissed,true); assert.equal(modal().children.find(n=>n.className==='ui-dialog-error').textContent,'Nested dialogs are unavailable during validation.'); modal().dispatchEvent(new Event('cancel',{cancelable:true})); assert.equal((await first).isConfirmed,false); });
