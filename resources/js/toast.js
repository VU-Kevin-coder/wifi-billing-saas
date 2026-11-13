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
            icon: type,
            title: type === 'success' ? 'Success' : type === 'error' ? 'Something went wrong' : type === 'warning' ? 'Attention' : 'Information',
            text: String(message),
            confirmButtonText: 'OK',
            confirmButtonColor: type === 'error' ? '#dc2626' : '#2563eb',
        });
    }

    window.dispatchEvent(new CustomEvent('ui-toast', { detail: { type, message } }));
}

window.toast = Object.fromEntries(['success', 'error', 'warning', 'info'].map((type) => [
    type,
    (message) => showToast(type, message),
]));
