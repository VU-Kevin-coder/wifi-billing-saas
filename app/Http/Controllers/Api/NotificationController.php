<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $notifications = $user->notifications()
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (DatabaseNotification $notification): array => $this->present($notification));

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $record = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $record->markAsRead();

        return response()->json([
            'success' => true,
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'unread_count' => 0,
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();
        $latest = $user->unreadNotifications()->latest()->first();

        return response()->json([
            'unread_count' => $user->unreadNotifications()->count(),
            'latest_unread' => $latest ? $this->present($latest) : null,
        ]);
    }

    private function present(DatabaseNotification $notification): array
    {
        $data = $notification->data;

        return [
            'id' => $notification->id,
            'type' => class_basename($notification->type),
            'title' => is_string($data['title'] ?? null) ? $data['title'] : 'Notification',
            'message' => is_string($data['message'] ?? null) ? $data['message'] : '',
            'action_url' => $this->safeActionUrl($data['action_url'] ?? null),
            'created_at' => $notification->created_at?->toIso8601String(),
            'read_at' => $notification->read_at?->toIso8601String(),
        ];
    }

    private function safeActionUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '' || str_contains($url, '\\') || preg_match('/[\x00-\x20]/', $url)) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return null;
        }

        if (! isset($parts['host'])) {
            return str_starts_with($url, '/') && ! str_starts_with($url, '//') ? $url : null;
        }

        if (! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        $baseDomain = strtolower((string) config('tenancy.base_domain', 'localhost'));
        $appHost = strtolower((string) parse_url(config('app.url'), PHP_URL_HOST));
        if ($host !== $baseDomain && $host !== $appHost && ! str_ends_with($host, '.'.$baseDomain)) {
            return null;
        }

        return $url;
    }
}
