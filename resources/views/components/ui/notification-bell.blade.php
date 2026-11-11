<div
    x-data="notificationBell()"
    @keydown.escape.window="open = false"
    @click.outside="open = false"
    class="relative"
>
    <button
        type="button"
        @click="toggle()"
        :aria-expanded="open.toString()"
        aria-label="Notifications"
        class="relative flex h-8 w-8 items-center justify-center rounded-full border border-gray-200 text-gray-500 transition-colors hover:bg-gray-50 hover:text-gray-700"
    >
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
        </svg>
        <span
            x-cloak
            x-show="unreadCount > 0"
            x-text="unreadCount > 99 ? '99+' : unreadCount"
            class="absolute -right-1 -top-1 min-w-4 rounded-full bg-red-600 px-1 text-center text-[9px] font-bold leading-4 text-white"
        ></span>
    </button>

    <div
        x-cloak
        x-show="open"
        x-transition.origin.top.right
        class="absolute right-0 z-50 mt-2 w-[min(22rem,calc(100vw-2rem))] overflow-hidden rounded-xl border border-gray-200 bg-white shadow-xl"
        role="dialog"
        aria-label="Notifications"
    >
        <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
            <div>
                <h2 class="text-sm font-semibold text-gray-900">Notifications</h2>
                <p class="text-xs text-gray-500"><span x-text="unreadCount"></span> unread</p>
            </div>
            <button
                type="button"
                @click="markAllRead()"
                :disabled="unreadCount === 0"
                class="text-xs font-semibold text-blue-600 hover:text-blue-800 disabled:cursor-not-allowed disabled:text-gray-400"
            >Mark all read</button>
        </div>

        <div class="max-h-96 divide-y divide-gray-100 overflow-y-auto">
            <template x-if="loading && notifications.length === 0">
                <p class="px-4 py-8 text-center text-sm text-gray-500">Loading notifications…</p>
            </template>
            <template x-if="!loading && notifications.length === 0">
                <p class="px-4 py-8 text-center text-sm text-gray-500">You’re all caught up.</p>
            </template>
            <template x-if="error">
                <div class="px-4 py-6 text-center">
                    <p class="text-sm text-red-600">Notifications could not be loaded.</p>
                    <button type="button" @click="fetchNotifications()" class="mt-2 text-xs font-semibold text-blue-600 hover:text-blue-800">Try again</button>
                </div>
            </template>
            <template x-for="notification in notifications" :key="notification.id">
                <button
                    type="button"
                    @click="openNotification(notification)"
                    class="flex w-full gap-3 px-4 py-3 text-left transition-colors hover:bg-gray-50"
                    :class="notification.read_at ? 'bg-white' : 'bg-blue-50/60'"
                >
                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="notification.read_at ? 'bg-transparent' : 'bg-blue-600'"></span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-xs font-semibold text-gray-900" x-text="notification.title"></span>
                        <span class="mt-1 block text-xs leading-relaxed text-gray-600" x-text="notification.message"></span>
                        <span class="mt-1 block text-[10px] text-gray-400" x-text="formatDate(notification.created_at)"></span>
                    </span>
                </button>
            </template>
        </div>
    </div>
</div>
