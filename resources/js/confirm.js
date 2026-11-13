function requestConfirmation(message, options = {}) {
    if (!window.Swal?.fire) {
        window.dispatchEvent(new CustomEvent('ui-toast', {
            detail: { type: 'error', message: 'Confirmation is unavailable. Reload the page and try again.' },
        }));

        return Promise.resolve(false);
    }

    return window.Swal.fire({
        title: options.title || 'Are you sure?',
        text: message,
        icon: options.icon || 'warning',
        showCancelButton: true,
        confirmButtonColor: options.confirmButtonColor || '#dc2626',
        cancelButtonColor: '#6b7280',
        confirmButtonText: options.confirmButtonText || 'Yes, continue',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: true,
    }).then((result) => result.isConfirmed);
}

window.confirmAction = requestConfirmation;

window.confirmWithReason = async function confirmWithReason(message, options = {}) {
    if (!window.Swal?.fire) {
        window.dispatchEvent(new CustomEvent('ui-toast', {
            detail: { type: 'error', message: 'Confirmation is unavailable. Reload the page and try again.' },
        }));

        return null;
    }

    const minLength = options.minLength || 3;
    const result = await window.Swal.fire({
        title: options.title || 'Confirm action',
        text: message,
        icon: 'warning',
        input: 'textarea',
        inputLabel: options.inputLabel || 'Reason',
        inputPlaceholder: options.inputPlaceholder || 'Enter a reason',
        inputAttributes: {
            maxlength: options.maxLength || 500,
            'aria-label': options.inputLabel || 'Reason',
        },
        inputValidator: (value) => (value || '').trim().length < minLength
            ? `Enter at least ${minLength} characters.`
            : undefined,
        showCancelButton: true,
        confirmButtonColor: options.confirmButtonColor || '#dc2626',
        cancelButtonColor: '#6b7280',
        confirmButtonText: options.confirmButtonText || 'Confirm',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: true,
    });

    return result.isConfirmed ? result.value.trim() : null;
};

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.dataset.confirmAction) {
        return;
    }

    if (form.dataset.confirmed === 'true') {
        delete form.dataset.confirmed;
        return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();
    const submitter = event.submitter;

    requestConfirmation(form.dataset.confirmAction, {
        confirmButtonText: form.dataset.confirmButtonText,
    }).then((confirmed) => {
        if (!confirmed) {
            return;
        }

        form.dataset.confirmed = 'true';
        form.requestSubmit(submitter || undefined);
    });
}, true);
