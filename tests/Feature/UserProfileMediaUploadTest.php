<?php

namespace Tests\Feature;

use App\Models\Level;
use App\Models\Profile;
use App\Models\User;
use App\Support\StoredMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UserProfileMediaUploadTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Level $basicLevel;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('spaces');

        $this->basicLevel = Level::create([
            'name' => 'Basic',
            'amount' => 0,
            'reg_bonus' => 0,
            'ref_bonus' => 0,
            'min_withdrawal' => 0,
            'earning_per_view' => 0,
            'earning_per_like' => 0,
            'earning_per_comment' => 0,
        ]);

        $this->artisan('passport:client', [
            '--personal' => true,
            '--name' => 'Payhankey Personal Access Client',
            '--provider' => 'users',
            '--no-interaction' => true,
        ]);

        $this->user = User::factory()->create([
            'username' => 'testuser',
            'name' => 'Test User',
            'email' => 'testuser@payhankey.com',
            'level_id' => $this->basicLevel->id,
        ]);
    }

    public function test_user_can_upload_avatar_via_dedicated_endpoint(): void
    {
        $avatarFile = UploadedFile::fake()->image('avatar.jpg', 300, 300);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/v1/user/avatar', [
                'avatar' => $avatarFile,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Avatar updated successfully',
            ]);

        $this->user->refresh();
        $this->assertNotNull($this->user->avatar);

        $storedPath = StoredMedia::resolvePath($this->user->avatar, 'spaces');
        $this->assertNotNull($storedPath);
        Storage::disk('spaces')->assertExists($storedPath);

        $response->assertJsonPath('data.avatar', $this->user->avatar);
        $response->assertJsonPath('data.user.avatar', $this->user->avatar);
    }

    public function test_uploading_new_avatar_deletes_previous_avatar(): void
    {
        $firstAvatar = UploadedFile::fake()->image('first_avatar.png', 200, 200);
        $this->actingAs($this->user, 'api')
            ->postJson('/v1/user/avatar', ['avatar' => $firstAvatar])
            ->assertStatus(200);

        $this->user->refresh();
        $oldPath = StoredMedia::resolvePath($this->user->avatar, 'spaces');
        Storage::disk('spaces')->assertExists($oldPath);

        $secondAvatar = UploadedFile::fake()->image('second_avatar.jpg', 200, 200);
        $this->actingAs($this->user, 'api')
            ->postJson('/v1/user/avatar', ['avatar' => $secondAvatar])
            ->assertStatus(200);

        $this->user->refresh();
        $newPath = StoredMedia::resolvePath($this->user->avatar, 'spaces');

        $this->assertNotEquals($oldPath, $newPath);
        Storage::disk('spaces')->assertMissing($oldPath);
        Storage::disk('spaces')->assertExists($newPath);
    }

    public function test_user_can_remove_avatar(): void
    {
        $avatarFile = UploadedFile::fake()->image('avatar.jpg', 200, 200);
        $this->actingAs($this->user, 'api')
            ->postJson('/v1/user/avatar', ['avatar' => $avatarFile])
            ->assertStatus(200);

        $this->user->refresh();
        $path = StoredMedia::resolvePath($this->user->avatar, 'spaces');
        Storage::disk('spaces')->assertExists($path);

        $response = $this->actingAs($this->user, 'api')
            ->deleteJson('/v1/user/avatar');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Avatar removed successfully',
                'data' => [
                    'avatar' => null,
                ],
            ]);

        $this->user->refresh();
        $this->assertNull($this->user->avatar);
        Storage::disk('spaces')->assertMissing($path);
    }

    public function test_user_can_upload_banner_via_dedicated_endpoint(): void
    {
        $bannerFile = UploadedFile::fake()->image('banner.jpg', 1200, 400);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/v1/user/banner', [
                'banner' => $bannerFile,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Banner updated successfully',
            ]);

        $this->user->refresh();
        $this->assertNotNull($this->user->banner);

        $storedPath = StoredMedia::resolvePath($this->user->banner, 'spaces');
        $this->assertNotNull($storedPath);
        Storage::disk('spaces')->assertExists($storedPath);

        $response->assertJsonPath('data.banner', $this->user->banner);
        $response->assertJsonPath('data.user.banner', $this->user->banner);
    }

    public function test_uploading_new_banner_deletes_previous_banner(): void
    {
        $firstBanner = UploadedFile::fake()->image('first_banner.jpg', 800, 300);
        $this->actingAs($this->user, 'api')
            ->postJson('/v1/user/banner', ['banner' => $firstBanner])
            ->assertStatus(200);

        $this->user->refresh();
        $oldPath = StoredMedia::resolvePath($this->user->banner, 'spaces');
        Storage::disk('spaces')->assertExists($oldPath);

        $secondBanner = UploadedFile::fake()->image('second_banner.png', 800, 300);
        $this->actingAs($this->user, 'api')
            ->postJson('/v1/user/banner', ['banner' => $secondBanner])
            ->assertStatus(200);

        $this->user->refresh();
        $newPath = StoredMedia::resolvePath($this->user->banner, 'spaces');

        $this->assertNotEquals($oldPath, $newPath);
        Storage::disk('spaces')->assertMissing($oldPath);
        Storage::disk('spaces')->assertExists($newPath);
    }

    public function test_user_can_remove_banner(): void
    {
        $bannerFile = UploadedFile::fake()->image('banner.jpg', 800, 300);
        $this->actingAs($this->user, 'api')
            ->postJson('/v1/user/banner', ['banner' => $bannerFile])
            ->assertStatus(200);

        $this->user->refresh();
        $path = StoredMedia::resolvePath($this->user->banner, 'spaces');
        Storage::disk('spaces')->assertExists($path);

        $response = $this->actingAs($this->user, 'api')
            ->deleteJson('/v1/user/banner');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Banner removed successfully',
                'data' => [
                    'banner' => null,
                ],
            ]);

        $this->user->refresh();
        $this->assertNull($this->user->banner);
        Storage::disk('spaces')->assertMissing($path);
    }

    public function test_user_can_upload_avatar_and_banner_via_update_profile(): void
    {
        $avatarFile = UploadedFile::fake()->image('avatar.png', 250, 250);
        $bannerFile = UploadedFile::fake()->image('cover.jpg', 1000, 350);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/v1/user/profile', [
                'avatar' => $avatarFile,
                'banner' => $bannerFile,
                'location' => 'Lagos, Nigeria',
                'about' => 'Software Engineer & Gamer',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Profile updated',
            ]);

        $this->user->refresh();
        $this->assertNotNull($this->user->avatar);
        $this->assertNotNull($this->user->banner);

        $avatarPath = StoredMedia::resolvePath($this->user->avatar, 'spaces');
        $bannerPath = StoredMedia::resolvePath($this->user->banner, 'spaces');
        Storage::disk('spaces')->assertExists($avatarPath);
        Storage::disk('spaces')->assertExists($bannerPath);

        $this->assertEquals('Lagos, Nigeria', $this->user->profile->location);
        $this->assertEquals('Software Engineer & Gamer', $this->user->profile->about);
    }

    public function test_user_can_remove_avatar_and_banner_via_update_profile_flags(): void
    {
        $avatarFile = UploadedFile::fake()->image('avatar.png', 250, 250);
        $bannerFile = UploadedFile::fake()->image('cover.jpg', 1000, 350);

        $this->actingAs($this->user, 'api')
            ->postJson('/v1/user/profile', [
                'avatar' => $avatarFile,
                'banner' => $bannerFile,
            ])
            ->assertStatus(200);

        $this->user->refresh();
        $avatarPath = StoredMedia::resolvePath($this->user->avatar, 'spaces');
        $bannerPath = StoredMedia::resolvePath($this->user->banner, 'spaces');
        Storage::disk('spaces')->assertExists($avatarPath);
        Storage::disk('spaces')->assertExists($bannerPath);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/v1/user/profile', [
                'remove_avatar' => true,
                'remove_banner' => true,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Profile updated',
            ]);

        $this->user->refresh();
        $this->assertNull($this->user->avatar);
        $this->assertNull($this->user->banner);

        Storage::disk('spaces')->assertMissing($avatarPath);
        Storage::disk('spaces')->assertMissing($bannerPath);
    }

    public function test_avatar_validation_fails_for_invalid_file_type_and_excessive_size(): void
    {
        $pdfFile = UploadedFile::fake()->create('document.pdf', 500, 'application/pdf');
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/v1/user/avatar', [
                'avatar' => $pdfFile,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['avatar']);

        // Over 5MB (5120KB) limit
        $oversizedImage = UploadedFile::fake()->create('huge_avatar.jpg', 6000, 'image/jpeg');
        $response2 = $this->actingAs($this->user, 'api')
            ->postJson('/v1/user/avatar', [
                'avatar' => $oversizedImage,
            ]);

        $response2->assertStatus(422)
            ->assertJsonValidationErrors(['avatar']);
    }

    public function test_banner_validation_fails_for_invalid_file_type_and_excessive_size(): void
    {
        $txtFile = UploadedFile::fake()->create('notes.txt', 100, 'text/plain');
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/v1/user/banner', [
                'banner' => $txtFile,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['banner']);

        // Over 10MB (10240KB) limit
        $oversizedBanner = UploadedFile::fake()->create('huge_banner.png', 11000, 'image/png');
        $response2 = $this->actingAs($this->user, 'api')
            ->postJson('/v1/user/banner', [
                'banner' => $oversizedBanner,
            ]);

        $response2->assertStatus(422)
            ->assertJsonValidationErrors(['banner']);
    }

    public function test_user_profile_endpoint_returns_avatar_and_banner(): void
    {
        $this->user->update([
            'avatar' => 'https://cdn.payhankey.com/payhankey_media/profiles/avatar-test.jpg',
            'banner' => 'https://cdn.payhankey.com/payhankey_media/profiles/banner-test.jpg',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/v1/user/profile/{$this->user->username}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'profile' => [
                    'username' => 'testuser',
                    'avatar' => 'https://cdn.payhankey.com/payhankey_media/profiles/avatar-test.jpg',
                    'banner' => 'https://cdn.payhankey.com/payhankey_media/profiles/banner-test.jpg',
                ],
            ]);
    }

    public function test_user_me_endpoint_returns_avatar_and_banner(): void
    {
        $this->user->update([
            'avatar' => 'https://cdn.payhankey.com/payhankey_media/profiles/avatar-me.png',
            'banner' => 'https://cdn.payhankey.com/payhankey_media/profiles/banner-me.png',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/v1/user/me');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user' => [
                        'username' => 'testuser',
                        'avatar' => 'https://cdn.payhankey.com/payhankey_media/profiles/avatar-me.png',
                        'banner' => 'https://cdn.payhankey.com/payhankey_media/profiles/banner-me.png',
                    ],
                ],
            ]);
    }

    public function test_unauthenticated_user_cannot_upload_or_remove_media(): void
    {
        $avatarFile = UploadedFile::fake()->image('avatar.jpg', 200, 200);
        $bannerFile = UploadedFile::fake()->image('banner.jpg', 200, 200);

        $this->postJson('/v1/user/avatar', ['avatar' => $avatarFile])->assertStatus(401);
        $this->deleteJson('/v1/user/avatar')->assertStatus(401);
        $this->postJson('/v1/user/banner', ['banner' => $bannerFile])->assertStatus(401);
        $this->deleteJson('/v1/user/banner')->assertStatus(401);
    }
}
