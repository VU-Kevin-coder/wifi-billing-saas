// State and keyboard handling for the authenticated mobile navigation drawer.
export function mobileSidebar() {
    return {
        sidebarCollapsed: false,
        mobileSidebarOpen: false,
        sidebarTrigger: null,
        openSidebar(trigger) {
            this.sidebarTrigger = trigger;
            this.sidebarCollapsed = false;
            this.mobileSidebarOpen = true;
            this.$nextTick(() => {
                if (this.mobileSidebarOpen) this.$refs.sidebarClose.focus();
            });
        },
        closeSidebar() {
            if (!this.mobileSidebarOpen) return;
            this.mobileSidebarOpen = false;
            this.$nextTick(() => {
                if (this.mobileSidebarOpen) return;
                if (this.sidebarTrigger?.isConnected && this.sidebarTrigger.getClientRects().length) {
                    this.sidebarTrigger.focus();
                } else {
                    this.$refs.mainContent.focus();
                }
            });
        },
        trapSidebarTab(event) {
            if (!this.mobileSidebarOpen || event.key !== 'Tab') return;
            const items = Array.from(this.$refs.sidebarDialog.querySelectorAll(
                'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
            )).filter(element => element.getClientRects().length && !element.closest('[inert]'));
            const first = items[0] || this.$refs.sidebarClose;
            const last = items[items.length - 1] || first;
            const active = document.activeElement;
            if (!items.includes(active) || (event.shiftKey && active === first) || (!event.shiftKey && active === last)) {
                event.preventDefault();
                (event.shiftKey ? last : first).focus();
            }
        },
        keepSidebarFocus(event) {
            if (this.mobileSidebarOpen && !this.$refs.sidebarDialog.contains(event.target)) {
                this.$refs.sidebarClose.focus();
            }
        },
        closeSidebarOnDesktop() {
            if (window.matchMedia('(min-width: 1024px)').matches) this.closeSidebar();
        },
    };
}
