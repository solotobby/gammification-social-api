<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserDeviceToken;
use App\Notifications\GeneralNotification;
use App\Services\ExpoPushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExpoPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_user_can_register_device_token_with_ip_and_location_type(): void
    {
        $response = $this->actingAs($this->user, 'api')->postJson('/v1/notifications/device-token', [
            'token' => 'ExponentPushToken[device-ios-token-1]',
            'platform' => 'ios',
            'device_name' => 'iPhone 15 Pro',
            'location_type' => 'cellular',
            'location' => 'London, United Kingdom',
            'ip_address' => '102.89.34.112',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Device token registered successfully',
                'data' => [
                    'token' => 'ExponentPushToken[device-ios-token-1]',
                    'platform' => 'ios',
                    'device_name' => 'iPhone 15 Pro',
                    'location_type' => 'cellular',
                    'location' => 'London, United Kingdom',
                    'ip_address' => '102.89.34.112',
                    'is_logged_out' => false,
                    'is_active' => true,
                ],
            ]);

        $this->assertDatabaseHas('user_device_tokens', [
            'user_id' => $this->user->id,
            'token' => 'ExponentPushToken[device-ios-token-1]',
            'platform' => 'ios',
            'device_name' => 'iPhone 15 Pro',
            'location_type' => 'cellular',
            'location' => 'London, United Kingdom',
            'ip_address' => '102.89.34.112',
            'is_logged_out' => false,
            'is_active' => true,
        ]);
    }

    public function test_user_can_register_location_with_city_and_country(): void
    {
        $response = $this->actingAs($this->user, 'api')->postJson('/v1/notifications/device-token', [
            'token' => 'ExponentPushToken[device-houston-token]',
            'platform' => 'android',
            'device_name' => 'Samsung S24',
            'city' => 'Houston',
            'country' => 'Texas, US',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'location' => 'Houston, Texas, US',
                ],
            ]);

        $this->assertDatabaseHas('user_device_tokens', [
            'token' => 'ExponentPushToken[device-houston-token]',
            'location' => 'Houston, Texas, US',
        ]);
    }

    public function test_user_can_register_multiple_devices_concurrently(): void
    {
        // Device 1: iPhone
        $this->actingAs($this->user, 'api')->postJson('/v1/notifications/device-token', [
            'token' => 'ExponentPushToken[iphone-token]',
            'platform' => 'ios',
            'device_name' => 'iPhone 15',
        ])->assertStatus(200);

        // Device 2: iPad
        $this->actingAs($this->user, 'api')->postJson('/v1/notifications/device-token', [
            'token' => 'ExponentPushToken[ipad-token]',
            'platform' => 'ios',
            'device_name' => 'iPad Air',
        ])->assertStatus(200);

        $this->assertEquals(2, $this->user->deviceTokens()->count());
        $this->assertEquals(2, $this->user->activeDeviceTokens()->count());
    }

    public function test_registering_existing_token_on_new_user_reassigns_cleanly(): void
    {
        $otherUser = User::factory()->create();

        // User A registers the device
        $this->actingAs($this->user, 'api')->postJson('/v1/notifications/device-token', [
            'token' => 'ExponentPushToken[shared-phone]',
            'device_name' => 'Shared Device',
        ])->assertStatus(200);

        $this->assertEquals($this->user->id, UserDeviceToken::where('token', 'ExponentPushToken[shared-phone]')->value('user_id'));

        // User B logs in and registers the same physical device token
        $this->actingAs($otherUser, 'api')->postJson('/v1/notifications/device-token', [
            'token' => 'ExponentPushToken[shared-phone]',
            'device_name' => 'Shared Device',
        ])->assertStatus(200);

        // Assert cleanly reassigned to User B
        $this->assertEquals(1, UserDeviceToken::where('token', 'ExponentPushToken[shared-phone]')->count());
        $this->assertEquals($otherUser->id, UserDeviceToken::where('token', 'ExponentPushToken[shared-phone]')->value('user_id'));
    }

    public function test_logout_keeps_device_token_active_and_marks_is_logged_out(): void
    {
        $this->actingAs($this->user, 'api')->postJson('/v1/notifications/device-token', [
            'token' => 'ExponentPushToken[persistent-phone]',
            'platform' => 'android',
        ])->assertStatus(200);

        $response = $this->actingAs($this->user, 'api')->postJson('/v1/logout', [
            'device_token' => 'ExponentPushToken[persistent-phone]',
        ]);

        $response->assertStatus(200);

        // Token must STILL exist in database so user can receive pushes after logout
        $this->assertDatabaseHas('user_device_tokens', [
            'user_id' => $this->user->id,
            'token' => 'ExponentPushToken[persistent-phone]',
            'is_logged_out' => true,
            'is_active' => true,
        ]);
    }

    public function test_user_can_explicitly_remove_device_token_via_delete(): void
    {
        $this->actingAs($this->user, 'api')->postJson('/v1/notifications/device-token', [
            'token' => 'ExponentPushToken[to-delete]',
        ])->assertStatus(200);

        $response = $this->actingAs($this->user, 'api')->deleteJson('/v1/notifications/device-token', [
            'token' => 'ExponentPushToken[to-delete]',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Device token removed successfully',
            ]);

        $this->assertDatabaseMissing('user_device_tokens', [
            'token' => 'ExponentPushToken[to-delete]',
        ]);
    }

    public function test_expo_push_service_sends_payload_to_all_user_devices(): void
    {
        Http::fake([
            'https://exp.host/--/api/v2/push/send' => Http::response([
                'data' => [
                    ['status' => 'ok', 'id' => 'ticket-1'],
                    ['status' => 'ok', 'id' => 'ticket-2'],
                ],
            ], 200),
        ]);

        // Register 2 devices (one logged in, one logged out)
        UserDeviceToken::create([
            'user_id' => $this->user->id,
            'token' => 'ExponentPushToken[device-active]',
            'platform' => 'ios',
            'is_logged_out' => false,
            'is_active' => true,
        ]);

        UserDeviceToken::create([
            'user_id' => $this->user->id,
            'token' => 'ExponentPushToken[device-logged-out]',
            'platform' => 'android',
            'is_logged_out' => true,
            'is_active' => true,
        ]);

        $service = app(ExpoPushNotificationService::class);
        $results = $service->sendToUser($this->user, 'New Comment', 'Someone replied to you', ['post_id' => '123']);

        $this->assertNotEmpty($results);

        Http::assertSent(function ($request) {
            $data = $request->data();
            return count($data) === 2
                && $data[0]['to'] === 'ExponentPushToken[device-active]'
                && $data[1]['to'] === 'ExponentPushToken[device-logged-out]'
                && $data[0]['title'] === 'New Comment';
        });
    }

    public function test_expo_push_service_auto_prunes_unregistered_devices(): void
    {
        UserDeviceToken::create([
            'user_id' => $this->user->id,
            'token' => 'ExponentPushToken[uninstalled-phone]',
            'platform' => 'ios',
            'is_active' => true,
        ]);

        Http::fake([
            'https://exp.host/--/api/v2/push/send' => Http::response([
                'data' => [
                    [
                        'status' => 'error',
                        'message' => 'DeviceNotRegistered',
                        'details' => ['error' => 'DeviceNotRegistered'],
                    ],
                ],
            ], 200),
        ]);

        $service = app(ExpoPushNotificationService::class);
        $service->sendToTokens(['ExponentPushToken[uninstalled-phone]'], 'Title', 'Body');

        // Verify dead token was pruned automatically
        $this->assertDatabaseMissing('user_device_tokens', [
            'token' => 'ExponentPushToken[uninstalled-phone]',
        ]);
    }

    public function test_general_notification_dispatches_via_expo_push_channel(): void
    {
        Http::fake([
            'https://exp.host/--/api/v2/push/send' => Http::response([
                'data' => [
                    ['status' => 'ok', 'id' => 'ticket-1'],
                ],
            ], 200),
        ]);

        UserDeviceToken::create([
            'user_id' => $this->user->id,
            'token' => 'ExponentPushToken[target-phone]',
            'platform' => 'ios',
            'is_active' => true,
        ]);

        $this->user->notify(new GeneralNotification([
            'title' => 'PayKoin Gift',
            'message' => 'You received 50 PK!',
            'type' => 'paykoin_gift',
            'meta' => ['amount' => 50],
        ]));

        // In-app notification saved in database
        $this->assertEquals(1, $this->user->notifications()->count());

        // Push dispatched to Expo
        Http::assertSent(function ($request) {
            $data = $request->data();
            return count($data) === 1
                && $data[0]['to'] === 'ExponentPushToken[target-phone]'
                && $data[0]['title'] === 'PayKoin Gift'
                && $data[0]['body'] === 'You received 50 PK!';
        });
    }
}
