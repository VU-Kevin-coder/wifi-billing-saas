<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class WithdrawalRequested extends Notification
{
    public function __construct(
        private readonly int $tenantId,
        private readonly string $tenantName,
        private readonly string $amount,
        private readonly string $reference,
        private readonly string $url,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => "Withdrawal request from {$this->tenantName}",
            'message' => "{$this->tenantName} requested a withdrawal of {$this->amount} (reference {$this->reference}).",
            'action_url' => $this->url,
            'tenant_id' => $this->tenantId,
        ];
    }
}
