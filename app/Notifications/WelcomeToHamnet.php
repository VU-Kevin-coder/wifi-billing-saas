<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeToHamnet extends Notification
{
    public function __construct(private readonly Tenant $tenant) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Welcome to Hamnet')
            ->greeting('Welcome, '.$notifiable->name.'!')
            ->line('Your '.$this->tenant->name.' workspace is ready.')
            ->action('Open your workspace', $this->tenant->url('/login'))
            ->line('You can now configure sites, packages, routers, and vouchers from your operator dashboard.');
    }
}
