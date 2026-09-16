<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Services\ExpoPushNotificationService;
use Illuminate\Notifications\Notification;
use Throwable;

class ExpoPushChannel
{
    public function __construct(protected ExpoPushNotificationService $expoService) {}

    /**
     * Send the given notification via Expo Push.
     */
    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User) {
            return;
        }

        try {
            if (method_exists($notification, 'toExpoPush')) {
                $payload = $notification->toExpoPush($notifiable);
                if (! empty($payload)) {
                    $this->expoService->sendToUser(
                        $notifiable,
                        $payload['title'] ?? 'Payhankey',
                        $payload['body'] ?? ($payload['message'] ?? ''),
                        $payload['data'] ?? [],
                        $payload['sound'] ?? 'default',
                        $payload['badge'] ?? null
                    );
                }

                return;
            }

            // Fallback for GeneralNotification or notifications exposing $data
            if (isset($notification->data) && is_array($notification->data)) {
                $data = $notification->data;
                $title = (string) ($data['title'] ?? 'Notification');
                $body = (string) ($data['message'] ?? '');
                $customData = [
                    'type' => $data['type'] ?? 'general',
                    'url' => $data['url'] ?? null,
                    'meta' => $data['meta'] ?? [],
                ];

                $this->expoService->sendToUser(
                    $notifiable,
                    $title,
                    $body,
                    $customData
                );
            }
        } catch (Throwable) {
            // Silently handle push failures so database notification is never broken
        }
    }
}
