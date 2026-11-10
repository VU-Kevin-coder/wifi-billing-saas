<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\WithdrawalRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_notification_feed_is_authenticated_and_returns_only_the_latest_twenty(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        for ($index = 1; $index <= 21; $index++) {
            $user->notify(new WithdrawalRequested(1, 'Acme', 'UGX 1,000', "WTH-{$index}", '/operator/wallet'));
        }
        $otherUser->notify(new WithdrawalRequested(2, 'Other', 'UGX 2,000', 'WTH-OTHER', '/operator/wallet'));

        $this->getJson('/api/notifications')
            ->assertUnauthorized();

        $this->actingAs($user)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(20, 'notifications')
            ->assertJsonPath('unread_count', 21)
            ->assertJsonPath('notifications.0.action_url', '/operator/wallet');

        $this->actingAs($otherUser)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.message', 'Other requested a withdrawal of UGX 2,000 (reference WTH-OTHER).');
    }

    public function test_a_user_cannot_mark_another_users_notification_as_read(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $owner->notify(new WithdrawalRequested(1, 'Acme', 'UGX 1,000', 'WTH-1', '/operator/wallet'));
        $notification = $owner->notifications()->firstOrFail();

        $this->actingAs($otherUser)
            ->postJson("/api/notifications/{$notification->id}/read")
            ->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_notifications_can_be_marked_read_individually_or_all_at_once(): void
    {
        $user = User::factory()->create();
        $user->notify(new WithdrawalRequested(1, 'Acme', 'UGX 1,000', 'WTH-1', '/operator/wallet'));
        $user->notify(new WithdrawalRequested(1, 'Acme', 'UGX 2,000', 'WTH-2', '/operator/wallet'));
        $first = $user->notifications()->oldest()->firstOrFail();

        $this->actingAs($user)
            ->postJson("/api/notifications/{$first->id}/read")
            ->assertOk()
            ->assertJsonPath('unread_count', 1);

        $this->postJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->assertSame(0, $user->unreadNotifications()->count());
    }

    public function test_external_action_urls_are_not_exposed(): void
    {
        $user = User::factory()->create();
        $user->notify(new WithdrawalRequested(1, 'Acme', 'UGX 1,000', 'WTH-1', 'https://attacker.example/'));

        $this->actingAs($user)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('notifications.0.action_url', null);
    }
}
