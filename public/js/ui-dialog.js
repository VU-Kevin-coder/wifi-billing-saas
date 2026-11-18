/* A small local, text-only SweetAlert-style dialog API. Never interpret messages as HTML. */
(() => {
    const queue = [];
    let active = false;
    let validating = null;
    let nestedRejected = false;

    // Re-evaluate on every Tab: validators and callers can add, remove, hide or
    // disable controls while the dialog is open. Positive tabindex comes first.
    function tabStops(dialog) {
        const nodes = Array.from(dialog.querySelectorAll('a[href], area[href], button, input, select, textarea, iframe, [tabindex], [contenteditable]:not([contenteditable="false"])'));
        const eligible = nodes.filter((node) => {
            if (node.closest('dialog') !== dialog || node.tabIndex < 0 || node.matches(':disabled') || node.closest('[hidden], [inert]')) return false;
            const style = window.getComputedStyle(node);
            return style.visibility !== 'hidden' && style.visibility !== 'collapse' && node.getClientRects().length > 0;
        });
        // Only the checked radio (or the first if none is checked) is a tab stop.
        const radios = eligible.filter((node) => node.tagName === 'INPUT' && node.type === 'radio' && node.name);
        return eligible.filter((node) => {
            if (!radios.includes(node)) return true;
            const group = radios.filter((radio) => radio.name === node.name && radio.form === node.form);
            return node === (group.find((radio) => radio.checked) || group[0]);
        }).map((node, index) => ({ node, index })).sort((a, b) => {
            const aTab = a.node.tabIndex || Infinity;
            const bTab = b.node.tabIndex || Infinity;
            return aTab - bTab || a.index - b.index;
        }).map(({ node }) => node);
    }

    function next() {
        if (active || queue.length === 0) return;
        active = true;
        const { options, resolve } = queue.shift();
        let previous, dialog;
        let finished = false, removalObserver;
        // Mutation delivery is asynchronous. A dialog removed and reinserted (or
        // closed and reopened) before confirmation is still no longer trustworthy.
        function invalidated(records = []) {
            return records.some((record) =>
                (record.type === 'attributes' && record.target === dialog && record.attributeName === 'open') ||
                (record.type === 'childList' && Array.from(record.removedNodes || []).some((node) => node === dialog || node.contains?.(dialog)))
            );
        }
        function finish(result) {
            if (finished) return;
            if (result.isConfirmed && invalidated(removalObserver?.takeRecords?.())) {
                result = { isConfirmed: false, isDismissed: true };
            }
            finished = true;
            removalObserver?.disconnect();
            if (validating === dialog) validating = null;
            // A validator can open a child modal and then resolve asynchronously.
            // Removing the parent would also destroy that independently owned modal.
            let observer, poll;
            const cleanup = () => {
                if (dialog?.isConnected && dialog.querySelectorAll('dialog[open]').length) return;
                observer?.disconnect();
                if (poll) clearTimeout(poll);
                const focusedDialog = document.activeElement?.closest?.('dialog[open]');
                const anotherDialogFocused = focusedDialog && focusedDialog !== dialog;
                // Removal or external closure while waiting is not authorization.
                if (result.isConfirmed && (!dialog?.isConnected || !dialog.open)) {
                    result = { isConfirmed: false, isDismissed: true };
                }
                try { if (dialog?.open) dialog.close(); } catch (_) {}
                try { dialog?.remove(); } catch (_) {}
                try { if (!anotherDialogFocused && previous?.isConnected && typeof previous.focus === 'function') previous.focus(); } catch (_) {}
                active = false;
                resolve(result);
                queueMicrotask(next);
            };
            if (dialog?.isConnected && dialog.querySelectorAll('dialog[open]').length) {
                try {
                    observer = new MutationObserver((records) => {
                        if (invalidated(records)) result = { isConfirmed: false, isDismissed: true };
                        cleanup();
                    });
                    observer.observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['open'] });
                    poll = setTimeout(() => { result = { isConfirmed: false, isDismissed: true }; try { dialog.remove(); } catch (_) {} cleanup(); }, 30000);
                } catch (_) {
                    // Older environments still wait for the child to close or be removed.
                    result = { isConfirmed: false, isDismissed: true };
                    try { dialog.remove(); } catch (_) {}
                }
            }
            cleanup();
        }
        try {
        previous = document.activeElement;
        dialog = document.createElement('dialog');
        dialog.className = 'ui-dialog';
        dialog.addEventListener('keydown', (event) => {
            if (event.key !== 'Tab' || event.altKey || event.ctrlKey || event.metaKey || event.defaultPrevented) return;
            // An independently opened dialog inside this one owns its own keyboard events.
            if (event.target.closest('dialog') !== dialog) return;
            const stops = tabStops(dialog);
            const index = stops.indexOf(document.activeElement);
            const nextIndex = index < 0 ? (event.shiftKey ? stops.length - 1 : 0)
                : (index + (event.shiftKey ? -1 : 1) + stops.length) % stops.length;
            event.preventDefault();
            (stops[nextIndex] || dialog).focus();
        });
        dialog.setAttribute('aria-labelledby', 'ui-dialog-title');
        dialog.setAttribute('aria-describedby', 'ui-dialog-message');
        const title = document.createElement('h2');
        title.id = 'ui-dialog-title';
        title.textContent = String(options.title || 'Information');
        const message = document.createElement('p');
        message.id = 'ui-dialog-message';
        message.textContent = String(options.text || '');
        dialog.append(title, message);

        let input;
        let error;
        if (options.input === 'textarea' || options.input === 'text') {
            const label = document.createElement('label');
            label.textContent = String(options.inputLabel || 'Response');
            input = document.createElement(options.input === 'textarea' ? 'textarea' : 'input');
            input.placeholder = String(options.inputPlaceholder || '');
            input.id = 'ui-dialog-input';
            label.htmlFor = input.id;
            const maxLength = Number(options.inputAttributes?.maxlength);
            if (Number.isInteger(maxLength) && maxLength > 0) input.maxLength = maxLength;
            error = document.createElement('p');
            error.className = 'ui-dialog-error';
            error.id = 'ui-dialog-error';
            error.setAttribute('role', 'alert');
            input.setAttribute('aria-describedby', error.id);
            dialog.append(label, input, error);
        }
        const actions = document.createElement('div');
        actions.className = 'ui-dialog-actions';
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'ui-dialog-cancel';
        cancel.textContent = String(options.cancelButtonText || 'Cancel');
        const confirm = document.createElement('button');
        confirm.type = 'button';
        confirm.className = 'ui-dialog-confirm';
        if (options.icon === 'warning' || options.icon === 'error') confirm.classList.add('ui-dialog-danger');
        confirm.textContent = String(options.confirmButtonText || 'OK');
        if (options.showCancelButton) actions.append(cancel);
        actions.append(confirm);
        dialog.append(actions);
        cancel.addEventListener('click', () => finish({ isConfirmed: false, isDismissed: true }));
        dialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            finish({ isConfirmed: false, isDismissed: true });
        });
        dialog.addEventListener('close', () => finish({ isConfirmed: false, isDismissed: true }));
        confirm.addEventListener('click', async () => {
            if (finished || confirm.disabled) return;
            confirm.disabled = true;
            try {
                const value = input?.value;
                let validation = null;
                if (input && options.inputValidator) {
                    validating = dialog;
                    nestedRejected = false;
                    let validationTimer;
                    try {
                        validation = await Promise.race([
                            Promise.resolve(options.inputValidator(value)),
                            new Promise((_, reject) => { validationTimer = setTimeout(() => reject(new Error('Validation timed out')), 30000); }),
                        ]);
                    } finally {
                        clearTimeout(validationTimer);
                        if (validating === dialog) validating = null;
                    }
                }
                if (finished) return;
                if (nestedRejected) validation = 'Nested dialogs are unavailable during validation.';
                if (validation) {
                    error.textContent = String(validation);
                    input.setAttribute('aria-invalid', 'true');
                    input.focus();
                    return;
                }
                finish({ isConfirmed: true, isDismissed: false, value });
            } catch (_) {
                if (error) error.textContent = 'Unable to validate the response. Please try again.';
            } finally {
                confirm.disabled = false;
            }
        });
            document.body.append(dialog);
            if (typeof dialog.showModal !== 'function') throw new Error('Modal dialogs unavailable');
            dialog.showModal();
            removalObserver = new MutationObserver((records) => {
                if (invalidated(records) || !dialog.isConnected || !dialog.open) finish({ isConfirmed: false, isDismissed: true });
            });
            removalObserver.observe(document.documentElement || document.body, {subtree:true, childList:true, attributes:true, attributeFilter:["open"]});
            (options.showCancelButton && options.focusCancel ? cancel : (input || confirm)).focus();
        } catch (_) {
            finish({ isConfirmed: false, isDismissed: true });
            try { window.dispatchEvent(new CustomEvent('ui-toast', { detail: { type: 'error', message: 'Dialog unavailable. Your action was not confirmed.' } })); } catch (_) {}
        }
    }

    // Matching text does not establish message identity or severity.

    window.Swal = {
        fire(options = {}) {
            // Nested validator dialogs fail closed rather than deadlocking.
            if (validating) { nestedRejected = true; return Promise.resolve({ isConfirmed: false, isDismissed: true }); }
            return new Promise((resolve) => {
                queue.push({ options, resolve });
                next();
            });
        },
    };
})();
