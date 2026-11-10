function uiToast(initial = {}) {
    return {
        visible: false,
        message: '',
        type: 'info',
        timer: null,

        init() {
            if (initial.message) {
                this.show(initial);
            }
        },

        show(payload = {}) {
            this.message = String(payload.message || '');
            this.type = ['success', 'error', 'warning', 'info'].includes(payload.type) ? payload.type : 'info';
            this.visible = Boolean(this.message);
            window.clearTimeout(this.timer);

            if (this.visible) {
                this.timer = window.setTimeout(() => this.dismiss(), this.type === 'error' ? 6000 : 4000);
            }
        },

        dismiss() {
            this.visible = false;
            window.clearTimeout(this.timer);
        },
    };
}

window.uiToast = uiToast;

function showToast(type, message) {
    if (window.Swal?.fire) {
        return window.Swal.fire({
            toast: true,
            position: 'bottom-end',
            icon: type,
            title: String(message),
            showConfirmButton: false,
            timer: type === 'error' ? 6000 : 4000,
            timerProgressBar: true,
        });
    }

    window.dispatchEvent(new CustomEvent('ui-toast', { detail: { type, message } }));
}

window.toast = Object.fromEntries(['success', 'error', 'warning', 'info'].map((type) => [
    type,
    (message) => showToast(type, message),
]));
