<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Community;
use App\Models\CommunityCategory;
use App\Models\CommunityPost;
use App\Models\CommunityPostComment;
use App\Models\Level;
use App\Models\Post;
use App\Models\User;
use App\Services\CommentService;
use App\Services\CommunityMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CommentRepliesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user1;
    protected User $user2;
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

        $this->user1 = User::factory()->create([
            'username' => 'alice',
            'name' => 'Alice Smith',
            'email' => 'alice@payhankey.com',
            'level_id' => $this->basicLevel->id,
        ]);

        $this->user2 = User::factory()->create([
            'username' => 'bob',
            'name' => 'Bob Jones',
            'email' => 'bob@payhankey.com',
            'level_id' => $this->basicLevel->id,
        ]);
    }

    public function test_timeline_post_comment_and_replies_flow(): void
    {
        $post = Post::create([
            'user_id' => $this->user1->id,
            'content' => 'Hello world feed post',
            'unicode' => '1234'.time(),
            'status' => 'LIVE',
            'media_status' => 'completed',
        ]);

        // 1. Post root comment via CommentService
        $commentService = app(CommentService::class);
        $rootComment = $commentService->addComment($post->id, $this->user1, 'This is a root comment');

        $this->assertNull($rootComment->parent_id);
        $this->assertFalse($rootComment->isReply());

        // 2. Post reply comment to root
        $reply1 = $commentService->addComment($post->id, $this->user2, 'This is a reply from Bob', $rootComment->id);

        $this->assertEquals($rootComment->id, $reply1->parent_id);
        $this->assertTrue($reply1->isReply());

        // 3. Post reply to the reply (should flatten to root)
        $reply2 = $commentService->addComment($post->id, $this->user1, 'This is a reply to reply', $reply1->id);

        $this->assertEquals($rootComment->id, $reply2->parent_id);
        $this->assertTrue($reply2->isReply());

        // 4. Test view post details endpoint returns nested replies
        $response = $this->actingAs($this->user1, 'api')
            ->getJson("/v1/timeline/post/{$post->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $comments = $response->json('data.comments.data');
        $this->assertCount(1, $comments); // only 1 root comment
        $this->assertEquals('This is a root comment', $comments[0]['message']);
        $this->assertEquals(2, $comments[0]['reply_count']);
        $this->assertCount(2, $comments[0]['replies']);
        $this->assertEquals('This is a reply from Bob', $comments[0]['replies'][0]['message']);
        $this->assertEquals('This is a reply to reply', $comments[0]['replies'][1]['message']);
    }

    public function test_timeline_post_comment_endpoint_accepts_parent_id(): void
    {
        $post = Post::create([
            'user_id' => $this->user1->id,
            'content' => 'Post to test endpoint',
            'unicode' => '5678'.time(),
            'status' => 'LIVE',
            'media_status' => 'completed',
        ]);

        $rootComment = Comment::create([
            'user_id' => $this->user1->id,
            'post_id' => $post->id,
            'message' => 'Existing root comment',
        ]);

        $response = $this->actingAs($this->user2, 'api')
            ->postJson('/v1/timeline/comment', [
                'post_id' => $post->id,
                'comment' => 'Reply via endpoint',
                'parent_id' => $rootComment->id,
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('success', true);
    }

    public function test_community_post_comment_and_replies_flow(): void
    {
        $category = CommunityCategory::create([
            'name' => 'Technology',
            'slug' => 'technology',
        ]);

        $community = Community::create([
            'user_id' => $this->user1->id,
            'community_categories_id' => $category->id,
            'name' => 'Dev Community',
            'slug' => 'dev-community',
            'description' => 'A community for developers',
            'type' => 'public',
        ]);

        // Join community as member
        app(CommunityMembershipService::class)->attachMember($community, $this->user2->id, 'member');

        $post = CommunityPost::create([
            'community_id' => $community->id,
            'user_id' => $this->user1->id,
            'content' => 'Welcome to the dev community post!',
        ]);

        // 1. Post root comment via endpoint
        $rootResp = $this->actingAs($this->user1, 'api')
            ->postJson("/v1/communities/{$community->id}/posts/{$post->id}/comments", [
                'content' => 'Root community comment',
            ]);

        $rootResp->assertStatus(201)
            ->assertJsonPath('success', true);
        $rootCommentId = $rootResp->json('data.id');

        // 2. Post reply via endpoint
        $replyResp = $this->actingAs($this->user2, 'api')
            ->postJson("/v1/communities/{$community->id}/posts/{$post->id}/comments", [
                'content' => 'Reply from Bob in community',
                'parent_id' => $rootCommentId,
            ]);

        $replyResp->assertStatus(201)
            ->assertJsonPath('success', true);
        $replyId = $replyResp->json('data.id');
        $this->assertEquals($rootCommentId, $replyResp->json('data.parent_id'));
        $this->assertTrue($replyResp->json('data.is_reply'));

        // 3. Post reply to reply (flattening check)
        $replyToReplyResp = $this->actingAs($this->user1, 'api')
            ->postJson("/v1/communities/{$community->id}/posts/{$post->id}/comments", [
                'content' => 'Alice reply to Bob',
                'parent_id' => $replyId,
            ]);

        $replyToReplyResp->assertStatus(201);
        $this->assertEquals($rootCommentId, $replyToReplyResp->json('data.parent_id'));

        // 4. Fetch comments list
        $listResp = $this->actingAs($this->user2, 'api')
            ->getJson("/v1/communities/{$community->id}/posts/{$post->id}/comments");

        $listResp->assertStatus(200)
            ->assertJsonPath('success', true);

        $comments = $listResp->json('data.data');
        $this->assertCount(1, $comments); // 1 root comment
        $this->assertEquals('Root community comment', $comments[0]['content']);
        $this->assertEquals(2, $comments[0]['reply_count']);
        $this->assertCount(2, $comments[0]['replies']);
        $this->assertEquals('Reply from Bob in community', $comments[0]['replies'][0]['content']);
        $this->assertEquals('Alice reply to Bob', $comments[0]['replies'][1]['content']);
    }
}
