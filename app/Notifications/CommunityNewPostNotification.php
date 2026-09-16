<?php

namespace App\Notifications;

use App\Models\Community;
use App\Models\CommunityPost;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class CommunityNewPostNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public CommunityPost $post,
        public User $author,
        public Community $community
    ) {}

    /**
     * Delivery channels: In-app database notification ONLY (no email).
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the database (in-app) representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $authorName = function_exists('displayName') ? displayName($this->author->name ?? 'A member') : ($this->author->name ?? 'A member');
        $snippet = Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($this->post->content ?? ''))), 80);
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $url = $baseUrl . '/community/' . ($this->community->slug ?? $this->community->id) . '#cpost-' . $this->post->id;

        $message = $snippet !== ''
            ? "{$authorName}: \"{$snippet}\""
            : "{$authorName} shared a new post in the community.";

        return [
            'title' => 'New post in ' . $this->community->name,
            'message' => $message,
            'icon' => 'fa-comments text-primary',
            'url' => $url,
            'type' => 'community_post',
            'meta' => [
                'community_id' => $this->community->id,
                'community_slug' => $this->community->slug,
                'community_name' => $this->community->name,
                'post_id' => $this->post->id,
                'author_id' => $this->author->id,
                'author_name' => $this->author->name,
                'author_username' => $this->author->username,
                'author_avatar' => $this->author->avatar,
            ],
            'community_id' => $this->community->id,
            'community_name' => $this->community->name,
            'post_id' => $this->post->id,
            'author_id' => $this->author->id,
            'author_name' => $this->author->name,
            'author_username' => $this->author->username,
            'author_avatar' => $this->author->avatar,
        ];
    }
}
