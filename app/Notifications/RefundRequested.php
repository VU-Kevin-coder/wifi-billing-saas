<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RefundRequested extends Notification
{
    public function __construct(
        public readonly int $refundId,
        public readonly string $tenantName,
        public readonly string $amount,
        public readonly string $saleReference,
        public readonly string $reason,
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
            'title' => "Refund request from {$this->tenantName}",
            'message' => "Refund {$this->amount} requested for sale {$this->saleReference}: {$this->reason}",
            'action_url' => $this->url,
            'tenant_id' => $this->tenantId,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject("Refund request from {$this->tenantName}")
            ->line("{$this->tenantName} has asked for a refund of {$this->amount} on sale {$this->saleReference}.")
            ->line("Reason given: {$this->reason}")
            ->line('Review it, refund the customer, and record the payout reference to approve.');

        if ($this->url) {
            $message->action('Review refund requests', $this->url);
        }

        return $message;
    }
}
