<?php

namespace App\Http\Controllers\V1\Notification;

use App\Http\Controllers\Controller;
use App\Services\ExpoPushNotificationService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationController extends Controller
{
    public function __construct(
        protected NotificationService $notificationService,
        protected ExpoPushNotificationService $expoPushNotificationService
    ) {}

    /**
     * GET /v1/notifications
     */
    public function index(Request $request)
    {
        $user = resolveApiUser($request);

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'filter' => ['sometimes', 'in:all,unread,read'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $filter = $validated['filter'] ?? 'all';
        $perPage = (int) ($validated['per_page'] ?? 20);

        try {
            $query = $user->notifications()->latest();

            if ($filter === 'unread') {
                $query->whereNull('read_at');
            } elseif ($filter === 'read') {
                $query->whereNotNull('read_at');
            }

            $paginator = $query->paginate($perPage);

            $paginator->getCollection()->transform(
                fn ($notification) => $this->notificationService->formatNotification($notification),
            );

            return response()->json([
                'success' => true,
                'message' => 'Notifications',
                'unread_count' => $user->unreadNotifications()->count(),
                'data' => $paginator,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to list notifications', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to load notifications',
            ], 500);
        }
    }

    /**
     * GET /v1/notifications/unread-count
     */
    public function unreadCount(Request $request)
    {
        $user = resolveApiUser($request);

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'unread_count' => $user->unreadNotifications()->count(),
            ],
        ]);
    }

    /**
     * POST /v1/notifications/{id}/read
     */
    public function markAsRead(Request $request, string $id)
    {
        $user = resolveApiUser($request);

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $notification = $user->notifications()->where('id', $id)->first();

        if (! $notification) {
            return response()->json(['success' => false, 'message' => 'Notification not found'], 404);
        }

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read',
            'data' => $this->notificationService->formatNotification($notification->fresh()),
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * POST /v1/notifications/read-all
     */
    public function markAllAsRead(Request $request)
    {
        $user = resolveApiUser($request);

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $user->unreadNotifications->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read',
            'unread_count' => 0,
        ]);
    }

    /**
     * DELETE /v1/notifications/{id}
     */
    public function destroy(Request $request, string $id)
    {
        $user = resolveApiUser($request);

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $notification = $user->notifications()->where('id', $id)->first();

        if (! $notification) {
            return response()->json(['success' => false, 'message' => 'Notification not found'], 404);
        }

        $notification->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notification deleted',
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * DELETE /v1/notifications
     */
    public function destroyAll(Request $request)
    {
        $user = resolveApiUser($request);

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'only_read' => ['sometimes', 'boolean'],
        ]);

        $query = $user->notifications();

        if ($request->boolean('only_read', $validated['only_read'] ?? false)) {
            $query->whereNotNull('read_at');
        }

        $deleted = $query->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notifications cleared',
            'deleted' => $deleted,
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * POST /v1/notifications/device-token
     */
    public function storeDeviceToken(Request $request)
    {
        $user = resolveApiUser($request);

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['sometimes', 'nullable', 'string', 'in:ios,android,web'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'device_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ip_address' => ['sometimes', 'nullable', 'ip'],
            'location_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'location' => ['sometimes', 'nullable', 'string', 'max:150'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'country' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $ip = $validated['ip_address'] ?? $request->ip();

        // Support both direct "location": "London, United Kingdom" or "city" + "country"
        $location = $validated['location'] ?? null;
        if (! $location && (! empty($validated['city']) || ! empty($validated['country']))) {
            $parts = array_filter([$validated['city'] ?? null, $validated['country'] ?? null]);
            $location = implode(', ', $parts);
        }

        $deviceToken = $this->expoPushNotificationService->registerToken(
            $user,
            $validated['token'],
            $validated['platform'] ?? null,
            $validated['device_name'] ?? null,
            $ip,
            $validated['location_type'] ?? null,
            $location,
            $validated['device_id'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Device token registered successfully',
            'data' => [
                'id' => $deviceToken->id,
                'token' => $deviceToken->token,
                'platform' => $deviceToken->platform,
                'device_name' => $deviceToken->device_name,
                'device_id' => $deviceToken->device_id,
                'ip_address' => $deviceToken->ip_address,
                'location_type' => $deviceToken->location_type,
                'location' => $deviceToken->location,
                'is_logged_out' => $deviceToken->is_logged_out,
                'is_active' => $deviceToken->is_active,
                'last_active_at' => $deviceToken->last_active_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * DELETE /v1/notifications/device-token
     */
    public function destroyDeviceToken(Request $request)
    {
        $user = resolveApiUser($request);

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'token' => ['required', 'string'],
        ]);

        $this->expoPushNotificationService->removeToken($validated['token']);

        return response()->json([
            'success' => true,
            'message' => 'Device token removed successfully',
        ]);
    }
}
