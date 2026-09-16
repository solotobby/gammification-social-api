<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostBoost;
use App\Models\PostBoostClick;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostBoostTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected User $otherUser;

    protected Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'username' => 'advertiser_john',
            'name' => 'John Advertiser',
        ]);

        $this->otherUser = User::factory()->create([
            'username' => 'stranger_bob',
            'name' => 'Bob Stranger',
        ]);

        Wallet::create([
            'user_id' => $this->user->id,
            'currency' => 'NGN',
            'balance' => 0,
            'promoter_balance' => 0,
            'referral_balance' => 0,
            'paykoin_spendable' => 300, // 300 PayKoins
            'paykoin_earned' => 0,
        ]);

        $this->post = Post::create([
            'user_id' => $this->user->id,
            'content' => 'Check out our new game launch!',
            'unicode' => 'boost_test_'.uniqid(),
            'status' => 'LIVE',
            'media_status' => 'completed',
        ]);
    }

    public function test_cannot_access_boost_config_unauthenticated(): void
    {
        $response = $this->getJson("/v1/timeline/post/{$this->post->id}/boost/config");
        $response->assertStatus(401);
    }

    public function test_can_get_boost_config(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson("/v1/timeline/post/{$this->post->id}/boost/config");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'rate_per_click_paykoin' => 3,
                    'min_clicks' => 10,
                    'is_boost_enabled' => true,
                    'user_spendable_paykoin' => 300,
                ],
            ]);

        $packages = $response->json('data.packages');
        $this->assertIsArray($packages);
        $this->assertNotEmpty($packages);
    }

    public function test_cannot_boost_another_users_post(): void
    {
        $response = $this->actingAs($this->otherUser, 'api')
            ->postJson("/v1/timeline/post/{$this->post->id}/boost", [
                'clicks' => 50,
                'target_url' => 'https://game.example.com',
                'cta' => 'Play Now',
            ]);

        $response->assertStatus(403);
    }

    public function test_cannot_boost_with_insufficient_paykoin(): void
    {
        // 50 clicks = 150 PK. Requesting 200 clicks = 600 PK, user only has 300 PK.
        $response = $this->actingAs($this->user, 'api')
            ->postJson("/v1/timeline/post/{$this->post->id}/boost", [
                'clicks' => 200,
                'target_url' => 'https://game.example.com',
                'cta' => 'Play Now',
            ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'success' => false,
            ]);
    }

    public function test_can_create_boost_successfully(): void
    {
        // User has 300 PK. 50 clicks * 3 = 150 PK.
        $response = $this->actingAs($this->user, 'api')
            ->postJson("/v1/timeline/post/{$this->post->id}/boost", [
                'clicks' => 50,
                'target_url' => 'https://game.example.com/play',
                'cta' => 'Play Now',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Post boosted successfully',
                'data' => [
                    'requested_clicks' => 50,
                    'remaining_clicks' => 50,
                    'total_cost' => 150,
                    'cta' => 'Play Now',
                    'target_url' => 'https://game.example.com/play',
                    'status' => 'active',
                ],
            ]);

        // Verify database state
        $this->assertDatabaseHas('post_boosts', [
            'post_id' => $this->post->id,
            'user_id' => $this->user->id,
            'total_clicks' => 50,
            'remaining_clicks' => 50,
            'status' => 'active',
        ]);

        // Post flag updated
        $this->post->refresh();
        $this->assertTrue($this->post->is_boosted);

        // Wallet debited 150 PK
        $wallet = $this->user->wallet()->first();
        $this->assertEquals(150, $wallet->paykoin_spendable);

        // Paykoin transaction logged
        $this->assertDatabaseHas('paykoin_transactions', [
            'user_id' => $this->user->id,
            'type' => 'post_boost',
            'pk_amount' => -150,
        ]);
    }

    public function test_cannot_create_duplicate_active_boost_for_same_post(): void
    {
        // First boost
        $this->actingAs($this->user, 'api')
            ->postJson("/v1/timeline/post/{$this->post->id}/boost", [
                'clicks' => 50,
                'target_url' => 'https://game.example.com/play',
                'cta' => 'Play Now',
            ])->assertStatus(201);

        // Second boost while first is active
        $response = $this->actingAs($this->user, 'api')
            ->postJson("/v1/timeline/post/{$this->post->id}/boost", [
                'clicks' => 50,
                'target_url' => 'https://game.example.com/play',
                'cta' => 'Play Now',
            ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'This post already has an active boost campaign.',
            ]);
    }

    public function test_boosted_post_includes_sponsored_object_in_feed(): void
    {
        $this->actingAs($this->user, 'api')
            ->postJson("/v1/timeline/post/{$this->post->id}/boost", [
                'clicks' => 50,
                'target_url' => 'https://game.example.com/play',
                'cta' => 'Install App',
            ])->assertStatus(201);

        $feedResponse = $this->actingAs($this->otherUser, 'api')
            ->getJson('/v1/timeline/feed');

        $feedResponse->assertStatus(200);
        $posts = $feedResponse->json('data.data');
        $this->assertNotEmpty($posts);

        $feedPost = collect($posts)->firstWhere('id', $this->post->id);
        $this->assertNotNull($feedPost);
        $this->assertTrue($feedPost['is_boosted']);
        $this->assertNotNull($feedPost['sponsored']);
        $this->assertEquals('Install App', $feedPost['sponsored']['cta']);
        $this->assertEquals('https://game.example.com/play', $feedPost['sponsored']['target_url']);
        $this->assertStringContainsString('/click', $feedPost['sponsored']['click_url']);
    }

    public function test_user_can_list_and_view_boosts(): void
    {
        $boost = PostBoost::create([
            'post_id' => $this->post->id,
            'user_id' => $this->user->id,
            'cta' => 'Sign Up',
            'target_url' => 'https://example.com/signup',
            'total_clicks' => 50,
            'remaining_clicks' => 45,
            'delivered_clicks' => 5,
            'rate_pk' => 3,
            'pk_cost' => 150,
            'status' => 'active',
        ]);

        // List
        $listResponse = $this->actingAs($this->user, 'api')
            ->getJson('/v1/boosts');

        $listResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
        $items = $listResponse->json('data.data');
        $this->assertCount(1, $items);
        $this->assertEquals($boost->id, $items[0]['id']);

        // Show
        $showResponse = $this->actingAs($this->user, 'api')
            ->getJson("/v1/boosts/{$boost->id}");

        $showResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'boost' => [
                        'id' => $boost->id,
                        'cta' => 'Sign Up',
                        'delivered_clicks' => 5,
                        'remaining_clicks' => 45,
                    ],
                ],
            ]);
    }

    public function test_user_can_pause_and_resume_boost(): void
    {
        $boost = PostBoost::create([
            'post_id' => $this->post->id,
            'user_id' => $this->user->id,
            'cta' => 'Sign Up',
            'target_url' => 'https://example.com/signup',
            'total_clicks' => 50,
            'remaining_clicks' => 50,
            'delivered_clicks' => 0,
            'rate_pk' => 3,
            'pk_cost' => 150,
            'status' => 'active',
        ]);
        $this->post->update(['is_boosted' => true]);

        // Pause
        $pauseResponse = $this->actingAs($this->user, 'api')
            ->postJson("/v1/boosts/{$boost->id}/pause");

        $pauseResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'paused',
                ],
            ]);

        $boost->refresh();
        $this->assertEquals('paused', $boost->status);

        // Resume
        $resumeResponse = $this->actingAs($this->user, 'api')
            ->postJson("/v1/boosts/{$boost->id}/resume");

        $resumeResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'active',
                ],
            ]);

        $boost->refresh();
        $this->assertEquals('active', $boost->status);
    }

    public function test_can_record_click_and_auto_completes_when_remaining_clicks_hits_zero(): void
    {
        $boost = PostBoost::create([
            'post_id' => $this->post->id,
            'user_id' => $this->user->id,
            'cta' => 'Shop Now',
            'target_url' => 'https://shop.example.com',
            'total_clicks' => 1,
            'remaining_clicks' => 1,
            'delivered_clicks' => 0,
            'rate_pk' => 3,
            'pk_cost' => 3,
            'status' => 'active',
        ]);
        $this->post->update(['is_boosted' => true]);

        $clickResponse = $this->postJson("/v1/boosts/{$boost->id}/click", [], [
            'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X)',
        ]);

        $clickResponse->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'target_url' => 'https://shop.example.com',
                    'cta' => 'Shop Now',
                ],
            ]);

        // Verify click record
        $this->assertDatabaseHas('post_boost_clicks', [
            'post_boost_id' => $boost->id,
            'post_id' => $this->post->id,
            'device' => 'Mobile',
        ]);

        // Verify boost auto-completed
        $boost->refresh();
        $this->assertEquals(0, $boost->remaining_clicks);
        $this->assertEquals(1, $boost->delivered_clicks);
        $this->assertEquals('completed', $boost->status);

        // Verify post flag reset
        $this->post->refresh();
        $this->assertFalse($this->post->is_boosted);
    }
}
