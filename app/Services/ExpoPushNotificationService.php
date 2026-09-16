<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserDeviceToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExpoPushNotificationService
{
    public const EXPO_PUSH_URL = 'https://exp.host/--/api/v2/push/send';

    /**
     * Check whether a string matches standard Expo push token patterns.
     */
    public function isValidExpoToken(string $token): bool
    {
        return str_starts_with($token, 'ExponentPushToken[') || str_starts_with($token, 'ExpoPushToken[');
    }

    /**
     * Register or update a device token for a user.
     * If the token was previously attached to another user on the same device,
     * it is smoothly reassigned to the new authenticated user.
     */
    public function registerToken(
        User $user,
        string $token,
        ?string $platform = null,
        ?string $deviceName = null,
        ?string $ipAddress = null,
        ?string $locationType = null
    ): UserDeviceToken {
        return UserDeviceToken::updateOrCreate(
            ['token' => $token],
            [
                'user_id' => $user->id,
                'platform' => $platform ? strtolower($platform) : null,
                'device_name' => $deviceName,
                'ip_address' => $ipAddress,
                'location_type' => $locationType,
                'is_logged_out' => false,
                'is_active' => true,
                'last_active_at' => now(),
            ]
        );
    }

    /**
     * Mark a device token as logged out.
     * Retains the token so the user can continue receiving re-engagement push notifications.
     */
    public function markLoggedOut(string $token): bool
    {
        return (bool) UserDeviceToken::where('token', $token)->update([
            'is_logged_out' => true,
            'last_active_at' => now(),
        ]);
    }

    /**
     * Explicitly remove / disable a device token (e.g. user toggles off push notifications).
     */
    public function removeToken(string $token): bool
    {
        return (bool) UserDeviceToken::where('token', $token)->delete();
    }

    /**
     * Send a push notification to all active devices of a user.
     * Includes devices even if currently logged out (for retention & re-engagement).
     *
     * @param  User|string  $user
     * @param  array<string, mixed>  $data
     * @return array<int, mixed>
     */
    public function sendToUser(
        User|string $user,
        string $title,
        string $body,
        array $data = [],
        string $sound = 'default',
        ?int $badge = null
    ): array {
        $userId = $user instanceof User ? $user->id : $user;

        $tokens = UserDeviceToken::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->pluck('token')
            ->all();

        if (empty($tokens)) {
            return [];
        }

        return $this->sendToTokens($tokens, $title, $body, $data, $sound, $badge);
    }

    /**
     * Send push notification to multiple users.
     *
     * @param  array<string>  $userIds
     * @param  array<string, mixed>  $data
     * @return array<int, mixed>
     */
    public function sendToUsers(
        array $userIds,
        string $title,
        string $body,
        array $data = [],
        string $sound = 'default',
        ?int $badge = null
    ): array {
        if (empty($userIds)) {
            return [];
        }

        $tokens = UserDeviceToken::query()
            ->whereIn('user_id', $userIds)
            ->where('is_active', true)
            ->pluck('token')
            ->all();

        if (empty($tokens)) {
            return [];
        }

        return $this->sendToTokens($tokens, $title, $body, $data, $sound, $badge);
    }

    /**
     * Dispatch push notifications to an array of Expo tokens in chunks of 100.
     * Automatically prunes any token that returns DeviceNotRegistered.
     *
     * @param  array<string>  $tokens
     * @param  array<string, mixed>  $data
     * @return array<int, mixed>
     */
    public function sendToTokens(
        array $tokens,
        string $title,
        string $body,
        array $data = [],
        string $sound = 'default',
        ?int $badge = null
    ): array {
        $validTokens = array_values(array_unique(array_filter($tokens, fn ($t) => is_string($t) && $this->isValidExpoToken($t))));

        if (empty($validTokens)) {
            return [];
        }

        $messages = [];
        foreach ($validTokens as $token) {
            $payload = [
                'to' => $token,
                'title' => $title,
                'body' => $body,
                'sound' => $sound,
                'channelId' => 'default',
                'priority' => 'high',
                'data' => $data,
            ];

            if ($badge !== null) {
                $payload['badge'] = $badge;
            }

            $messages[] = $payload;
        }

        $chunks = array_chunk($messages, 100);
        $allResults = [];

        foreach ($chunks as $chunk) {
            try {
                $response = Http::withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])->timeout(10)->post(self::EXPO_PUSH_URL, $chunk);

                $json = $response->json();
                $allResults[] = $json;

                // Handle token invalidation / auto-pruning
                $tickets = $json['data'] ?? [];
                if (is_array($tickets)) {
                    foreach ($tickets as $index => $ticket) {
                        $error = $ticket['details']['error'] ?? null;
                        if ($error === 'DeviceNotRegistered') {
                            $staleToken = $chunk[$index]['to'] ?? null;
                            if ($staleToken) {
                                UserDeviceToken::where('token', $staleToken)->delete();
                                Log::info('Pruned unregistered Expo push token', ['token' => $staleToken]);
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                Log::error('Failed to send Expo push notification chunk', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $allResults;
    }
}
