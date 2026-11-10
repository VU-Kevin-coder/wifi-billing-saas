<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class WithdrawalApproved extends Notification
{
    public function __construct(
        private readonly int $tenantId,
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
            'title' => 'Withdrawal approved',
            'message' => "Withdrawal {$this->reference} for {$this->amount} has been approved.",
            'action_url' => $this->url,
            'tenant_id' => $this->tenantId,
        ];
    }
}
