<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GeneralNotification extends Notification
{
    use Queueable;

    /**
     * @param  array{title?: string, message?: string, icon?: string, url?: string, type?: string, meta?: array}  $data
     */
    public function __construct(
        public array $data,
        public bool $sendMail = false,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->sendMail
            ? ['database', 'mail']
            : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->data['title'] ?? 'Notification')
            ->line($this->data['message'] ?? 'You have a new notification.')
            ->action('View', $this->data['url'] ?? url('/'))
            ->line('Thank you for using Payhankey.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->data['title'] ?? 'Notification',
            'message' => $this->data['message'] ?? null,
            'icon' => $this->data['icon'] ?? 'bell',
            'url' => $this->data['url'] ?? null,
            'type' => $this->data['type'] ?? 'general',
            'meta' => $this->data['meta'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
