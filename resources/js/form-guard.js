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

    form.querySelectorAll('[data-ui-original-html]').forEach((button) => {
        button.disabled = false;
        button.removeAttribute('aria-busy');
        if (button instanceof HTMLButtonElement) {
            button.innerHTML = button.dataset.uiOriginalHtml;
        } else {
            button.value = button.dataset.uiOriginalHtml;
        }
        delete button.dataset.uiOriginalHtml;
    });
}

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() === 'get' || form.hasAttribute('data-no-submit-guard')) {
        return;
    }

    if (form.dataset.uiSubmitting === 'true') {
        event.preventDefault();
        event.stopImmediatePropagation();
        return;
    }

    form.dataset.uiSubmitting = 'true';
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
        submitterField.name = submitter.name;
        submitterField.value = submitter.value;
        form.appendChild(submitterField);
    }

    submitter.dataset.uiOriginalHtml = submitter instanceof HTMLButtonElement ? submitter.innerHTML : submitter.value;
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
        restoreForm(form);
        const keyField = form.querySelector('input[name="_idempotency_key"]');
        if (keyField) {
            keyField.value = createIdempotencyKey();
        }
    });
});
