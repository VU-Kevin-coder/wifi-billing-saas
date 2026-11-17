function createIdempotencyKey() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);

    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
}

function restoreForm(form) {
    delete form.dataset.uiSubmitting;
    form.querySelectorAll('[data-ui-submitter-copy]').forEach((field) => field.remove());

    // form.elements includes submit buttons associated via the form attribute.
    Array.from(form.elements).filter((button) => button.hasAttribute('data-ui-original-html')).forEach((button) => {
        button.disabled = button.dataset.uiOriginalDisabled === 'true';
        delete button.dataset.uiOriginalDisabled;
        button.removeAttribute('aria-busy');
        if (button instanceof HTMLButtonElement) {
            button.innerHTML = button.dataset.uiOriginalHtml;
        } else {
            button.value = button.dataset.uiOriginalHtml;
        }
        delete button.dataset.uiOriginalHtml;
    });
    form.dispatchEvent(new CustomEvent('ui:form-restored'));
}

// Settings saves are requests, not navigations: keep the dirty-page warning active
// while the request is in flight. A transport failure is ambiguous, so require a
// reload rather than pretending a client-generated key makes retry safe.
async function saveSettings(form, event) {
    event.preventDefault();
    const message = document.querySelector('[data-ui-save-message]');
    const show = text => { if (message) { message.textContent = text; message.hidden = false; } };
    if (form.dataset.uiSubmitting === 'true' || form.dataset.uiSaveUncertain === 'true') return;
    if (typeof window.FormDataEvent !== 'function' || typeof window.fetch !== 'function' || typeof window.AbortController !== 'function') {
        show('This browser cannot safely submit these settings. Use a supported browser.');
        return;
    }
    let body;
    try { body = new FormData(form); } catch (_) {
        show('Unable to prepare the settings. Nothing was sent.');
        return;
    }
    form.dataset.uiSubmitting = 'true';
    const controls = Array.from(form.elements).filter(control => control.type !== 'hidden');
    const disabled = controls.map(control => control.disabled);
    controls.forEach(control => { control.disabled = true; });
    if (message) message.hidden = true;
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 30000);
    let saved = false;
    let redirect;
    try {
        const response = await window.fetch(form.action, {
            method: 'POST', body, credentials: 'same-origin', redirect: 'error',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-Settings-Async': '1' },
            signal: controller.signal,
        });
        const data = await response.json();
        if (response.status === 422) {
            const errors = Object.values(data.errors || {}).flat();
            show(errors.length ? errors.join(' ') : (data.message || 'The settings could not be validated.'));
        } else if (response.ok && data.saved === true && typeof data.redirect === 'string') {
            const target = new URL(data.redirect, window.location.href);
            if (target.origin !== window.location.origin || target.pathname !== new URL(form.action, window.location.href).pathname) throw new Error('Unexpected redirect');
            redirect = target.href;
            saved = true;
        } else {
            throw new Error('Save outcome unknown');
        }
    } catch (_) {
        form.dataset.uiSaveUncertain = 'true';
        show('The save result could not be confirmed. Reload and review the current settings before trying again.');
    } finally {
        clearTimeout(timer);
        controls.forEach((control, index) => { control.disabled = disabled[index]; });
        delete form.dataset.uiSubmitting;
        if (saved) {
            form.dispatchEvent(new CustomEvent('ui:form-saved'));
            window.location.assign(redirect);
        } else {
            form.dispatchEvent(new CustomEvent('ui:form-restored'));
        }
    }
}

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (event.defaultPrevented || !(form instanceof HTMLFormElement) || form.method.toLowerCase() === 'get' || form.hasAttribute('data-no-submit-guard')) {
        return;
    }

    if (form.hasAttribute('data-ui-async-settings')) {
        saveSettings(form, event);
        return;
    }

    if (form.dataset.uiSubmitting === 'true') {
        event.preventDefault();
        event.stopImmediatePropagation();
        return;
    }

    form.dataset.uiSubmitting = 'true';
    // A later submit handler may cancel navigation (for example, a validation error).
    // Restore the button after propagation so the user can correct and retry.
    queueMicrotask(() => {
        if (event.defaultPrevented && form.dataset.uiSubmitting === 'true') restoreForm(form);
    });
    const keyField = form.querySelector('input[name="_idempotency_key"]') || document.createElement('input');
    keyField.type = 'hidden';
    keyField.name = '_idempotency_key';
    keyField.value = keyField.value || createIdempotencyKey();

    if (!keyField.isConnected) {
        form.appendChild(keyField);
    }

    const submitter = event.submitter;
    if (!(submitter instanceof HTMLButtonElement || submitter instanceof HTMLInputElement)) {
        return;
    }

    if (submitter.name && submitter.value) {
        const submitterField = document.createElement('input');
        submitterField.type = 'hidden';
        submitterField.dataset.uiSubmitterCopy = 'true';
        submitterField.name = submitter.name;
        submitterField.value = submitter.value;
        form.appendChild(submitterField);
    }

    submitter.dataset.uiOriginalHtml = submitter instanceof HTMLButtonElement ? submitter.innerHTML : submitter.value;
    submitter.dataset.uiOriginalDisabled = String(submitter.disabled);
    submitter.disabled = true;
    submitter.setAttribute('aria-busy', 'true');
    const loadingText = submitter.dataset.loadingText || 'Saving…';
    const spinner = '<svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>';

    if (submitter instanceof HTMLButtonElement) {
        submitter.replaceChildren();
        submitter.insertAdjacentHTML('afterbegin', spinner);
        const label = document.createElement('span');
        label.textContent = loadingText;
        submitter.appendChild(label);
    } else {
        submitter.value = loadingText;
    }
}, true);

window.addEventListener('pageshow', () => {
    document.querySelectorAll('form[data-ui-submitting="true"]').forEach((form) => {
        // An asynchronous request can still be pending when pageshow fires.
        if (form.hasAttribute('data-ui-async-settings')) return;
        restoreForm(form);
        const keyField = form.querySelector('input[name="_idempotency_key"]');
        if (keyField) {
            keyField.value = createIdempotencyKey();
        }
    });
});
