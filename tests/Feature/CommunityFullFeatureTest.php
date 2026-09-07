<?php

namespace Tests\Feature;

use App\Models\Community;
use App\Models\CommunityCategory;
use App\Models\CommunityPost;
use App\Models\CommunityPostComment;
use App\Models\CommunitySubscription;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommunityFullFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $member;

    protected User $otherUser;

    protected CommunityCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create([
            'username' => 'owner_user',
            'name' => 'Community Owner',
        ]);

        $this->member = User::factory()->create([
            'username' => 'member_user',
            'name' => 'Community Member',
        ]);

        $this->otherUser = User::factory()->create([
            'username' => 'other_user',
            'name' => 'Other User',
        ]);

        Wallet::create(['user_id' => $this->owner->id, 'currency' => 'NGN', 'balance' => 0, 'promoter_balance' => 0, 'referral_balance' => 0]);
        Wallet::create(['user_id' => $this->member->id, 'currency' => 'NGN', 'balance' => 0, 'promoter_balance' => 0, 'referral_balance' => 0]);
        Wallet::create(['user_id' => $this->otherUser->id, 'currency' => 'NGN', 'balance' => 0, 'promoter_balance' => 0, 'referral_balance' => 0]);

        $this->category = CommunityCategory::create([
            'name' => 'Tech & Gaming',
            'slug' => 'tech-gaming',
            'icon' => 'tech-icon',
        ]);

        Storage::fake('spaces');
    }

    public function test_fee_preview_returns_correct_calculations(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/v1/communities/fee-preview', [
                'type' => 'paid',
                'monthly_fee' => 10000,
                'fee_payer' => 'creator',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'memberCharge' => 10000,
                    'platformCut' => 1000,
                    'creatorPayout' => 9000,
                ],
            ]);
    }

    public function test_community_crud_lifecycle(): void
    {
        // 1. Create free community (type: public)
        $createRes = $this->actingAs($this->owner, 'api')
            ->postJson('/v1/communities', [
                'community_categories_id' => $this->category->id,
                'name' => 'Developers Hub',
                'description' => 'A place for devs to connect',
                'type' => 'public',
            ]);

        $createRes->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Developers Hub',
                    'slug' => 'developers-hub',
                ],
            ]);

        $communityId = $createRes->json('data.id');

        // 2. Update community details
        $updateRes = $this->actingAs($this->owner, 'api')
            ->putJson("/v1/communities/{$communityId}", [
                'name' => 'Developers Hub Pro',
                'description' => 'An updated description for developers',
            ]);

        $updateRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Developers Hub Pro',
                ],
            ]);

        // 3. Upload & remove logo
        $logoFile = UploadedFile::fake()->image('avatar.jpg', 200, 200);
        $logoRes = $this->actingAs($this->owner, 'api')
            ->postJson("/v1/communities/{$communityId}/logo", [
                'logo' => $logoFile,
            ]);

        $logoRes->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertNotNull(Community::find($communityId)->logo);

        $removeLogoRes = $this->actingAs($this->owner, 'api')
            ->deleteJson("/v1/communities/{$communityId}/logo");

        $removeLogoRes->assertStatus(200);
        $this->assertNull(Community::find($communityId)->logo);

        // 4. Archive & Unarchive
        $archiveRes = $this->actingAs($this->owner, 'api')
            ->postJson("/v1/communities/{$communityId}/archive");

        $archiveRes->assertStatus(200)
            ->assertJson(['success' => true]);
        $this->assertNotNull(Community::find($communityId)->archived_at);

        $unarchiveRes = $this->actingAs($this->owner, 'api')
            ->postJson("/v1/communities/{$communityId}/unarchive");

        $unarchiveRes->assertStatus(200);
        $this->assertNull(Community::find($communityId)->archived_at);

        // 5. Non-owner cannot delete
        $nonOwnerDelete = $this->actingAs($this->member, 'api')
            ->deleteJson("/v1/communities/{$communityId}");
        $nonOwnerDelete->assertStatus(403);

        // 6. Owner can delete
        $ownerDelete = $this->actingAs($this->owner, 'api')
            ->deleteJson("/v1/communities/{$communityId}");
        $ownerDelete->assertStatus(200);
        $this->assertNull(Community::find($communityId));
    }

    public function test_member_moderation_flow(): void
    {
        // Setup community with owner and member
        $community = Community::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->owner->id,
            'community_categories_id' => $this->category->id,
            'name' => 'Moderation Club',
            'slug' => 'moderation-club',
            'type' => 'public',
            'members_count' => 2,
        ]);

        // Attach owner as admin
        $community->members()->attach($this->owner->id, [
            'role' => 'admin',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        // Attach member as normal member
        $community->members()->attach($this->member->id, [
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        // 1. List active members
        $listRes = $this->actingAs($this->owner, 'api')
            ->getJson("/v1/communities/{$community->id}/members");

        $listRes->assertStatus(200)
            ->assertJson(['success' => true]);
        $this->assertCount(2, $listRes->json('data.data'));

        // 2. Promote member to admin
        $promoteRes = $this->actingAs($this->owner, 'api')
            ->postJson("/v1/communities/{$community->id}/members/{$this->member->id}/promote");

        $promoteRes->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertEquals(
            'admin',
            $community->members()->where('users.id', $this->member->id)->first()->pivot->role
        );

        // 3. Demote back to member
        $demoteRes = $this->actingAs($this->owner, 'api')
            ->postJson("/v1/communities/{$community->id}/members/{$this->member->id}/demote");

        $demoteRes->assertStatus(200);
        $this->assertEquals(
            'member',
            $community->members()->where('users.id', $this->member->id)->first()->pivot->role
        );

        // 4. Ban member
        $banRes = $this->actingAs($this->owner, 'api')
            ->postJson("/v1/communities/{$community->id}/members/{$this->member->id}/ban");

        $banRes->assertStatus(200);
        $this->assertEquals(
            'banned',
            $community->allMembers()->where('users.id', $this->member->id)->first()->pivot->status
        );

        // 5. List banned members
        $bannedListRes = $this->actingAs($this->owner, 'api')
            ->getJson("/v1/communities/{$community->id}/members/banned");

        $bannedListRes->assertStatus(200);
        $this->assertCount(1, $bannedListRes->json('data.data'));

        // 6. Unban member
        $unbanRes = $this->actingAs($this->owner, 'api')
            ->postJson("/v1/communities/{$community->id}/members/{$this->member->id}/unban");

        $unbanRes->assertStatus(200);
        $this->assertEquals(
            'active',
            $community->members()->where('users.id', $this->member->id)->first()->pivot->status
        );

        // 7. Remove member
        $removeRes = $this->actingAs($this->owner, 'api')
            ->deleteJson("/v1/communities/{$community->id}/members/{$this->member->id}");

        $removeRes->assertStatus(200);
        $this->assertFalse(
            $community->members()->where('users.id', $this->member->id)->exists()
        );
    }

    public function test_post_and_comment_deletion(): void
    {
        $community = Community::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->owner->id,
            'community_categories_id' => $this->category->id,
            'name' => 'Posts Club',
            'slug' => 'posts-club',
            'type' => 'public',
            'members_count' => 2,
        ]);

        $community->members()->attach($this->owner->id, ['role' => 'admin', 'status' => 'active']);
        $community->members()->attach($this->member->id, ['role' => 'member', 'status' => 'active']);

        // Member creates a post
        $postRes = $this->actingAs($this->member, 'api')
            ->postJson("/v1/communities/{$community->id}/posts", [
                'content' => 'Hello this is an exclusive post #laravel',
            ]);

        $postRes->assertStatus(201);
        $postId = $postRes->json('data.id');

        // Other user creates a comment
        $commentRes = $this->actingAs($this->owner, 'api')
            ->postJson("/v1/communities/{$community->id}/posts/{$postId}/comments", [
                'content' => 'Welcome to the club!',
            ]);

        $commentRes->assertStatus(201);
        $commentId = $commentRes->json('data.id');

        // Test Feed Search query
        $feedRes = $this->actingAs($this->member, 'api')
            ->getJson("/v1/communities/{$community->id}/posts?search=exclusive");
        $feedRes->assertStatus(200);
        $this->assertCount(1, $feedRes->json('data.data'));

        // Delete comment
        $delCommentRes = $this->actingAs($this->owner, 'api')
            ->deleteJson("/v1/communities/{$community->id}/posts/{$postId}/comments/{$commentId}");
        $delCommentRes->assertStatus(200);
        $this->assertNull(CommunityPostComment::find($commentId));

        // Delete post
        $delPostRes = $this->actingAs($this->member, 'api')
            ->deleteJson("/v1/communities/{$community->id}/posts/{$postId}");
        $delPostRes->assertStatus(200);
        $this->assertNull(CommunityPost::find($postId));
    }

    public function test_community_analytics_endpoint(): void
    {
        $community = Community::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->owner->id,
            'community_categories_id' => $this->category->id,
            'name' => 'Analytics Community',
            'slug' => 'analytics-community',
            'type' => 'public',
            'members_count' => 1,
        ]);

        $community->members()->attach($this->owner->id, ['role' => 'admin', 'status' => 'active']);

        // Unauthorized user gets 403
        $forbiddenRes = $this->actingAs($this->otherUser, 'api')
            ->getJson("/v1/communities/{$community->id}/analytics");
        $forbiddenRes->assertStatus(403);

        // Owner gets analytics stats
        $analyticsRes = $this->actingAs($this->owner, 'api')
            ->getJson("/v1/communities/{$community->id}/analytics");

        $analyticsRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'community_id' => $community->id,
                    'stats' => [
                        'members_total' => 1,
                        'posts_total' => 0,
                    ],
                ],
            ]);
    }

    public function test_paid_community_subscription_and_earnings(): void
    {
        $community = Community::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->owner->id,
            'community_categories_id' => $this->category->id,
            'name' => 'Paid VIP Club',
            'slug' => 'paid-vip-club',
            'type' => 'paid',
            'billing_type' => 'subscription',
            'billing_interval' => 'monthly',
            'monthly_fee' => 5000,
            'currency' => 'NGN',
            'platform_fee_percent' => 10,
            'fee_payer' => 'creator',
            'members_count' => 1,
        ]);

        $community->members()->attach($this->owner->id, ['role' => 'admin', 'status' => 'active']);

        // Check subscription status (unsubscribed)
        $statusRes = $this->actingAs($this->member, 'api')
            ->getJson("/v1/communities/{$community->id}/subscription/status");

        $statusRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'has_subscription' => false,
                    'is_active' => false,
                ],
            ]);

        // Create an active subscription directly to test earnings dashboard
        CommunitySubscription::create([
            'id' => (string) Str::uuid(),
            'community_id' => $community->id,
            'user_id' => $this->member->id,
            'billing_type' => 'subscription',
            'billing_interval' => 'monthly',
            'fee_payer' => 'creator',
            'amount' => 5000,
            'platform_fee' => 500,
            'creator_amount' => 4500,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
            'gateway' => 'flutterwave',
            'gateway_reference' => 'TEST-SUB-REF-001',
        ]);

        // Subscription status (now subscribed)
        $statusActiveRes = $this->actingAs($this->member, 'api')
            ->getJson("/v1/communities/{$community->id}/subscription/status");

        $statusActiveRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'has_subscription' => true,
                    'is_active' => true,
                ],
            ]);

        // Owner checks earnings
        $earningsRes = $this->actingAs($this->owner, 'api')
            ->getJson("/v1/communities/{$community->id}/earnings");

        $earningsRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'stats' => [
                        'gross' => 5000,
                        'platform_fee' => 500,
                        'creator_amount' => 4500,
                        'count' => 1,
                        'active_subscribers_count' => 1,
                    ],
                ],
            ]);

        // Non-owner gets 422
        $nonOwnerEarnings = $this->actingAs($this->member, 'api')
            ->getJson("/v1/communities/{$community->id}/earnings");
        $nonOwnerEarnings->assertStatus(422);
    }

    public function test_flutterwave_webhook_activates_community_subscription(): void
    {
        config(['services.env.flutterwave_webhook_hash' => 'test-flw-secret-hash']);

        $community = Community::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->owner->id,
            'community_categories_id' => $this->category->id,
            'name' => 'Webhook VIP Community',
            'slug' => 'webhook-vip-community',
            'type' => 'paid',
            'billing_type' => 'subscription',
            'billing_interval' => 'monthly',
            'monthly_fee' => 20,
            'currency' => 'USD',
            'platform_fee_percent' => 10,
            'fee_payer' => 'creator',
            'members_count' => 1,
        ]);

        $txRef = 'COM-TEST-FLW-'.Str::random(8);

        $transaction = Transaction::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->member->id,
            'ref' => $txRef,
            'amount' => 20,
            'currency' => 'USD',
            'provider' => 'flutterwave',
            'status' => 'initiated',
            'type' => 'community_subscription',
            'action' => 'Debit',
            'description' => 'Payment for community subscription',
            'meta' => [
                'community_id' => $community->id,
                'community_name' => $community->name,
            ],
        ]);

        $webhookPayload = [
            'event' => 'charge.completed',
            'data' => [
                'id' => 1234567,
                'tx_ref' => $txRef,
                'status' => 'successful',
                'amount' => 20,
                'currency' => 'USD',
            ],
        ];

        $response = $this->postJson('/v1/webhooks/flutterwave', $webhookPayload, [
            'verif-hash' => 'test-flw-secret-hash',
        ]);

        $response->assertStatus(200);

        // Verify transaction is marked successful
        $this->assertEquals('successful', $transaction->fresh()->status);

        // Verify subscription is active
        $subscription = CommunitySubscription::where('community_id', $community->id)
            ->where('user_id', $this->member->id)
            ->first();

        $this->assertNotNull($subscription);
        $this->assertEquals('active', $subscription->status);
        $this->assertEquals(20, (float) $subscription->amount);

        // Verify user is now attached as member of the community
        $this->assertTrue(
            $community->members()->where('users.id', $this->member->id)->exists()
        );
    }
}
