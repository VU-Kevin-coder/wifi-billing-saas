function requestConfirmation(message, options = {}) {
    if (!window.Swal?.fire) {
        return Promise.resolve(window.confirm(message));
    }

    return window.Swal.fire({
        title: options.title || 'Are you sure?',
        text: message,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#6b7280',
        confirmButtonText: options.confirmButtonText || 'Yes, continue',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: true,
    }).then((result) => result.isConfirmed);
}

window.confirmAction = requestConfirmation;

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
