<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SiteAgentOffline extends Notification
{
    public function __construct(
        public readonly int $agentId,
        public readonly string $agentName,
        public readonly string $siteName,
        public readonly string $tenantName,
        public readonly ?string $lastSeenAt,
        public readonly string $url,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Site agent is offline',
            'message' => "{$this->agentName} at {$this->siteName} ({$this->tenantName}) has not checked in since "
                .($this->lastSeenAt ?? 'it was registered').'.',
            'action_url' => $this->url,
            'site_agent_id' => $this->agentId,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject("Site agent offline: {$this->siteName}")
            ->greeting('Site agent needs attention')
            ->line("{$this->agentName} for {$this->tenantName} at {$this->siteName} has not checked in.")
            ->line('Last check-in: '.($this->lastSeenAt ?? 'Never'));

        return $message->action('Review platform health', $this->url);
    }
}
