<?php

namespace App\Notifications;

use App\Models\Community;
use App\Models\User;
use App\Notifications\Channels\ExpoPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CommunityMemberJoinedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Community $community,
        public User $joinedUser
    ) {}

    /**
     * Delivery channels: email, in-app database notification, and Expo push.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail', ExpoPushChannel::class];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $joinedName = function_exists('displayName') ? displayName($this->joinedUser->name) : $this->joinedUser->name;
        $username = '@' . ($this->joinedUser->username ?? 'user');
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $communityUrl = $baseUrl . '/community/' . ($this->community->slug ?? $this->community->id);

        return (new MailMessage)
            ->subject("🎉 New member joined {$this->community->name}!")
            ->greeting("Hello {$notifiable->name},")
            ->line("Great news! **{$joinedName}** ({$username}) has just joined your community **{$this->community->name}**.")
            ->action('View Community', $communityUrl)
            ->line("Thank you for creating an active space on " . config('app.name') . "!");
    }

    /**
     * @return array<string, mixed>
     */
    public function toExpoPush(object $notifiable): array
    {
        $db = $this->toDatabase($notifiable);

        return [
            'title' => $db['title'],
            'body' => $db['message'],
            'sound' => 'default',
            'data' => [
                'type' => 'community_join',
                'community_id' => $this->community->id,
                'community_slug' => $this->community->slug,
                'user_id' => $this->joinedUser->id,
            ],
        ];
    }

    /**
     * Get the database (in-app) representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $joinedName = function_exists('displayName') ? displayName($this->joinedUser->name) : $this->joinedUser->name;
        $username = '@' . ($this->joinedUser->username ?? 'user');
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $communityUrl = $baseUrl . '/community/' . ($this->community->slug ?? $this->community->id);

        return [
            'title' => 'New member in ' . $this->community->name,
            'message' => "{$joinedName} ({$username}) joined your community.",
            'icon' => 'fa-user-plus text-success',
            'url' => $communityUrl,
            'type' => 'community_join',
            'meta' => [
                'community_id' => $this->community->id,
                'community_slug' => $this->community->slug,
                'community_name' => $this->community->name,
                'user_id' => $this->joinedUser->id,
                'user_name' => $this->joinedUser->name,
                'username' => $this->joinedUser->username,
                'avatar' => $this->joinedUser->avatar,
            ],
            'community_id' => $this->community->id,
            'community_name' => $this->community->name,
            'user_id' => $this->joinedUser->id,
            'user_name' => $this->joinedUser->name,
            'username' => $this->joinedUser->username,
            'avatar' => $this->joinedUser->avatar,
        ];
    }
}
