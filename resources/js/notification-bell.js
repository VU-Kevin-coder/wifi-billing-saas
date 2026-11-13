window.notificationBell = function notificationBell() {
    return {
        open: false,
        loading: false,
        unreadCount: 0,
        notifications: [],
        latestUnreadId: null,
        pollTimer: null,
        error: false,

        init() {
            this.fetchUnreadCount(true);
            this.pollTimer = window.setInterval(() => this.fetchUnreadCount(), 30000);
        },

        destroy() {
            window.clearInterval(this.pollTimer);
        },

        async toggle() {
            this.open = !this.open;
            if (this.open) {
                await this.fetchNotifications();
            }
        },

        async fetchNotifications() {
            this.loading = true;
            this.error = false;
            try {
                const result = await window.apiFetch('/api/notifications', { feedback: false });
                this.notifications = result.notifications;
                this.unreadCount = result.unread_count;
                this.latestUnreadId = this.notifications.find((item) => !item.read_at)?.id || this.latestUnreadId;
            } catch (error) {
                this.error = true;
                console.error('Unable to load notifications.', error);
            } finally {
                this.loading = false;
            }
        },

        async fetchUnreadCount(initial = false) {
            try {
                const result = await window.apiFetch('/api/notifications/unread-count', { feedback: false });
                const previousCount = this.unreadCount;
                this.unreadCount = result.unread_count;
                const latest = result.latest_unread;

                if (!initial && latest && latest.id !== this.latestUnreadId && this.unreadCount > previousCount) {
                    window.toast?.info(`New notification: ${latest.title}`);
                }

                if (latest) {
                    this.latestUnreadId = latest.id;
                }
            } catch (error) {
                console.error('Unable to check notifications.', error);
            }
        },

        async markAllRead() {
            try {
                await window.apiFetch('/api/notifications/read-all', { method: 'POST' });
                this.notifications = this.notifications.map((item) => ({ ...item, read_at: new Date().toISOString() }));
                this.unreadCount = 0;
            } catch (error) {
                this.error = true;
                console.error('Unable to mark notifications as read.', error);
            }
        },

        async openNotification(notification) {
            if (!notification.read_at) {
                try {
                    const result = await window.apiFetch(`/api/notifications/${encodeURIComponent(notification.id)}/read`, {
                        method: 'POST',
                    });
                    this.unreadCount = result.unread_count;
                    notification.read_at = new Date().toISOString();
                } catch (error) {
                    console.error('Unable to update notification.', error);
                    return;
                }
            }

            if (notification.action_url) {
                window.location.assign(notification.action_url);
            }
        },

        formatDate(value) {
            if (!value) {
                return '';
            }

            return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
        },
    };
};
