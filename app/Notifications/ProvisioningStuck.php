<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells operator admins that a customer has paid but router access is not
 * active. Built from plain values so it never depends on tenant scoping.
 */
class ProvisioningStuck extends Notification
{
    public function __construct(
        public readonly int $sessionId,
        public readonly string $reason,
        public readonly int $minutes,
        public readonly ?string $packageName,
        public readonly ?string $routerName,
        public readonly bool $expired,
        public readonly ?string $url = null,
        public readonly ?int $tenantId = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Customer access is not active',
            'message' => ($this->packageName ? "A customer paid for {$this->packageName}" : 'A customer paid')
                ." but access is not active. {$this->reason} Waiting {$this->minutes} minute(s).",
            'action_url' => $this->url,
            'tenant_id' => $this->tenantId,
            'session_id' => $this->sessionId,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Action needed: a customer paid but has no Wi-Fi access')
            ->line(($this->packageName ? "A customer paid for \"{$this->packageName}\"" : 'A customer paid for a package')
                .' but router access is not active'.($this->routerName ? " on {$this->routerName}" : '').'.')
            ->line("Waiting for: {$this->minutes} minute(s). Session #{$this->sessionId}.")
            ->line("Reason: {$this->reason}");

        $message->line($this->expired
            ? 'The package has now expired without ever being activated. Please contact the customer and arrange a refund or replacement.'
            : 'Check that the site agent is running and can reach the router. The system keeps retrying automatically.');

        if ($this->url) {
            $message->action('Open sessions', $this->url);
        }

        return $message;
    }
}
