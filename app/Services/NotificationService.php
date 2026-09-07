<?php

namespace App\Services;

use App\Mail\GeneralMail;
use App\Models\User;
use App\Notifications\GeneralNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class NotificationService
{
    /**
     * Send an in-app (database) notification, optionally with a rich HTML email.
     *
     * @param  array{
     *     title: string,
     *     message: string,
     *     icon?: string,
     *     url?: string|null,
     *     type?: string,
     *     meta?: array<string, mixed>
     * }  $data
     */
    public function send(
        User $user,
        array $data,
        bool $sendEmail = true,
        ?string $emailSubject = null,
        ?string $emailBody = null,
    ): void {
        try {
            $user->notify(new GeneralNotification([
                'title' => $data['title'],
                'message' => $data['message'],
                'icon' => $data['icon'] ?? 'bell',
                'url' => $data['url'] ?? null,
                'type' => $data['type'] ?? 'general',
                'meta' => $data['meta'] ?? [],
            ], false));
        } catch (Throwable $e) {
            Log::warning('Failed to store in-app notification', [
                'user_id' => $user->id,
                'type' => $data['type'] ?? 'general',
                'message' => $e->getMessage(),
            ]);
        }

        if (! $sendEmail || blank($user->email)) {
            return;
        }

        try {
            Mail::to($user->email)->send(new GeneralMail(
                (object) ['name' => $user->name, 'email' => $user->email],
                $emailSubject ?: $data['title'],
                $emailBody ?: e($data['message']),
            ));
        } catch (Throwable $e) {
            Log::warning('Failed to send notification email', [
                'user_id' => $user->id,
                'type' => $data['type'] ?? 'general',
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function communityUrl(string $slug): string
    {
        return rtrim((string) config('app.frontend_url', config('app.url')), '/').'/community/'.$slug;
    }

    public function inviteAcceptUrl(string $token): string
    {
        return rtrim((string) config('app.frontend_url', config('app.url')), '/').'/community/invite/'.$token;
    }

    public function giftsUrl(): string
    {
        return rtrim((string) config('app.frontend_url', config('app.url')), '/').'/wallet';
    }

    /**
     * @return array<string, mixed>
     */
    public function formatNotification($notification): array
    {
        $data = is_array($notification->data) ? $notification->data : [];

        return [
            'id' => $notification->id,
            'type' => $data['type'] ?? class_basename($notification->type),
            'title' => $data['title'] ?? 'Notification',
            'message' => $data['message'] ?? null,
            'icon' => $data['icon'] ?? 'bell',
            'url' => $data['url'] ?? null,
            'meta' => $data['meta'] ?? [],
            'read_at' => $notification->read_at?->toIso8601String(),
            'is_read' => $notification->read_at !== null,
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
