<?php

namespace Tests\Feature;

use App\Models\Follow;
use App\Models\Level;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FollowersFollowingListTest extends TestCase
{
    use RefreshDatabase;

    protected User $userA;
    protected User $userB;
    protected User $userC;
    protected Level $basicLevel;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->userA = User::factory()->create([
            'username' => 'alice',
            'name' => 'Alice Johnson',
            'level_id' => $this->basicLevel->id,
        ]);
        Profile::create([
            'user_id' => $this->userA->id,
            'about' => 'Alice bio here',
        ]);

        $this->userB = User::factory()->create([
            'username' => 'bob',
            'name' => 'Bob Williams',
            'level_id' => $this->basicLevel->id,
        ]);
        Profile::create([
            'user_id' => $this->userB->id,
            'about' => 'Bob bio here',
        ]);

        $this->userC = User::factory()->create([
            'username' => 'charlie',
            'name' => 'Charlie Brown',
            'level_id' => $this->basicLevel->id,
        ]);
        Profile::create([
            'user_id' => $this->userC->id,
            'about' => 'Charlie bio here',
        ]);
    }

    public function test_get_followers_list_successfully(): void
    {
        // Bob and Charlie follow Alice
        Follow::create(['follower_id' => $this->userB->id, 'following_id' => $this->userA->id]);
        Follow::create(['follower_id' => $this->userC->id, 'following_id' => $this->userA->id]);

        // Alice follows Bob back
        Follow::create(['follower_id' => $this->userA->id, 'following_id' => $this->userB->id]);

        // Alice views her own followers
        $response = $this->actingAs($this->userA, 'api')
            ->getJson('/v1/user/profile/alice/followers');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $followers = $response->json('data.data');
        $this->assertCount(2, $followers);

        // Find Bob in followers list
        $bobEntry = collect($followers)->firstWhere('username', 'bob');
        $this->assertNotNull($bobEntry);
        $this->assertEquals('Bob Williams', $bobEntry['name']);
        $this->assertEquals('Bob bio here', $bobEntry['about']);
        $this->assertTrue($bobEntry['is_following']); // Alice follows Bob
        $this->assertFalse($bobEntry['is_me']);

        // Find Charlie in followers list
        $charlieEntry = collect($followers)->firstWhere('username', 'charlie');
        $this->assertNotNull($charlieEntry);
        $this->assertFalse($charlieEntry['is_following']); // Alice does not follow Charlie
    }

    public function test_get_following_list_successfully(): void
    {
        // Alice follows Bob and Charlie
        Follow::create(['follower_id' => $this->userA->id, 'following_id' => $this->userB->id]);
        Follow::create(['follower_id' => $this->userA->id, 'following_id' => $this->userC->id]);

        // Bob views who Alice is following
        $response = $this->actingAs($this->userB, 'api')
            ->getJson('/v1/user/profile/alice/following');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $following = $response->json('data.data');
        $this->assertCount(2, $following);

        // Bob should see is_me=true for his own profile in Alice's following list
        $bobEntry = collect($following)->firstWhere('username', 'bob');
        $this->assertNotNull($bobEntry);
        $this->assertTrue($bobEntry['is_me']);
    }

    public function test_returns_404_for_non_existent_username(): void
    {
        $response = $this->actingAs($this->userA, 'api')
            ->getJson('/v1/user/profile/nonexistent_user_9999/followers');

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'User not found');
    }
}
