<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EnrollmentConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $courseTitle,
        public readonly string $orderNumber
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your course enrollment is confirmed')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Your payment is confirmed and you are enrolled in '.$this->courseTitle.'.')
            ->line('Order: '.$this->orderNumber);
    }
}
