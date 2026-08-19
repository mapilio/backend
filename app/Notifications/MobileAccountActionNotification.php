<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MobileAccountActionNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $subject,
        private readonly string $intro,
        private readonly string $action,
        private readonly string $url,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject)
            ->line($this->intro)
            ->action($this->action, $this->url)
            ->line('If you did not request this, you can safely ignore this email.');
    }
}
