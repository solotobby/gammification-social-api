<?php

namespace Tests\Feature;

use App\Jobs\SendCommunityNewPostNotificationJob;
use App\Models\Comment;
use App\Models\Community;
use App\Models\CommunityCategory;
use App\Models\CommunityPost;
use App\Models\Level;
use App\Models\Post;
use App\Models\User;
use App\Models\UserDeviceToken;
use App\Notifications\Channels\ExpoPushChannel;
use App\Notifications\CommunityMemberJoinedNotification;
use App\Notifications\CommunityNewPostNotification;
use App\Notifications\GeneralNotification;
use App\Services\CommentService;
use App\Services\CommunityMembershipService;
use App\Services\CommunityPostService;
use App\Services\FollowService;
use App\Services\LikeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class PushNotificationEventsTest extends TestCase
{
    use RefreshDatabase;

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
    }

    protected function makeUser(string $name = 'User'): User
    {
        return User::factory()->create([
            'username' => Str::lower(Str::random(10)),
            'name' => $name,
            'email' => Str::random(10) . '@example.com',
            'level_id' => $this->basicLevel->id,
        ]);
    }

    public function test_post_like_dispatches_notification_to_post_owner_and_not_on_self_like(): void
    {
        Notification::fake();

        $author = $this->makeUser('Author');
        $liker = $this->makeUser('Liker');

        $post = Post::create([
            'user_id' => $author->id,
            'content' => 'Hello timeline world',
            'unicode' => 'post_' . Str::random(10),
            'status' => 'LIVE',
            'media_status' => 'completed',
        ]);

        $likeService = app(LikeService::class);

        // Another user likes the post -> author notified
        $likeService->toggle($post->unicode, $liker);

        Notification::assertSentTo(
            $author,
            GeneralNotification::class,
            function (GeneralNotification $notification) use ($author) {
                $expoPayload = $notification->toExpoPush($author);
                $this->assertEquals('post_like', $expoPayload['data']['type']);
                $this->assertStringContainsString('liked your post', $expoPayload['title']);
                $this->assertContains(ExpoPushChannel::class, $notification->via($author));
                return true;
            }
        );

        Notification::fake(); // Reset

        // Self-like by author -> author should NOT be notified
        $likeService->toggle($post->unicode, $author);

        Notification::assertNotSentTo($author, GeneralNotification::class);
    }

    public function test_post_comment_and_reply_dispatch_notifications(): void
    {
        Notification::fake();

        $author = $this->makeUser('Post Author');
        $commenter = $this->makeUser('Commenter');
        $replier = $this->makeUser('Replier');

        $post = Post::create([
            'user_id' => $author->id,
            'content' => 'First post',
            'unicode' => 'post_' . Str::random(10),
            'status' => 'LIVE',
            'media_status' => 'completed',
        ]);

        $commentService = app(CommentService::class);

        // Commenter comments on post
        $comment = $commentService->addComment($post->id, $commenter, 'Nice post!');

        Notification::assertSentTo(
            $author,
            GeneralNotification::class,
            function (GeneralNotification $notification) use ($author) {
                $expo = $notification->toExpoPush($author);
                $this->assertEquals('post_comment', $expo['data']['type']);
                $this->assertStringContainsString('commented on your post', $expo['title']);
                return true;
            }
        );

        Notification::fake(); // Reset

        // Replier replies to Commenter's comment
        $commentService->addComment($post->id, $replier, 'I agree with this!', $comment->id);

        Notification::assertSentTo(
            $commenter,
            GeneralNotification::class,
            function (GeneralNotification $notification) use ($commenter) {
                $expo = $notification->toExpoPush($commenter);
                $this->assertEquals('comment_reply', $expo['data']['type']);
                $this->assertStringContainsString('replied to your comment', $expo['title']);
                return true;
            }
        );
    }

    public function test_follow_and_unfollow_dispatch_notifications(): void
    {
        Notification::fake();

        $follower = $this->makeUser('Follower');
        $target = $this->makeUser('Target');

        $followService = app(FollowService::class);

        // Follow
        $followService->toggle($follower, $target);

        Notification::assertSentTo(
            $target,
            GeneralNotification::class,
            function (GeneralNotification $notification) use ($target) {
                $expo = $notification->toExpoPush($target);
                $this->assertEquals('user_follow', $expo['data']['type']);
                $this->assertStringContainsString('started following you', $expo['title']);
                return true;
            }
        );

        Notification::fake(); // Reset

        // Unfollow
        $followService->toggle($follower, $target);

        Notification::assertSentTo(
            $target,
            GeneralNotification::class,
            function (GeneralNotification $notification) use ($target) {
                $expo = $notification->toExpoPush($target);
                $this->assertEquals('user_unfollow', $expo['data']['type']);
                $this->assertStringContainsString('unfollowed you', $expo['title']);
                return true;
            }
        );
    }

    public function test_community_events_dispatch_notifications(): void
    {
        Notification::fake();

        $owner = $this->makeUser('Community Owner');
        $author = $this->makeUser('Post Author');
        $member = $this->makeUser('Engaged Member');
        $replier = $this->makeUser('Comment Replier');

        $category = CommunityCategory::create([
            'name' => 'General',
            'slug' => 'general-' . Str::random(5),
        ]);

        $community = Community::create([
            'user_id' => $owner->id,
            'community_categories_id' => $category->id,
            'name' => 'Tech Enthusiasts',
            'slug' => 'tech-enthusiasts-' . Str::random(6),
            'description' => 'A community for tech lovers',
            'type' => 'public',
        ]);

        $membershipService = app(CommunityMembershipService::class);
        $postService = app(CommunityPostService::class);

        // 1. Owner attaches to own community (no notification)
        $membershipService->attachMember($community, $owner->id, 'owner');
        Notification::assertNothingSent();

        // 2. Member joins community -> Owner gets notified
        $membershipService->attachMember($community, $author->id);
        $membershipService->attachMember($community, $member->id);
        $membershipService->attachMember($community, $replier->id);

        Notification::assertSentTo(
            $owner,
            CommunityMemberJoinedNotification::class,
            function (CommunityMemberJoinedNotification $notification) use ($owner) {
                $expo = $notification->toExpoPush($owner);
                $this->assertEquals('community_join', $expo['data']['type']);
                $this->assertContains(ExpoPushChannel::class, $notification->via($owner));
                return true;
            }
        );

        Notification::fake(); // Reset

        // 3. Author creates post in community -> Members get notified
        $created = $postService->create($author, $community->id, [
            'content' => 'Welcome everyone to our tech community!',
        ]);
        $postId = $created['post']['id'];

        (new SendCommunityNewPostNotificationJob($postId))->handle();

        Notification::assertSentTo(
            $member,
            CommunityNewPostNotification::class,
            function (CommunityNewPostNotification $notification) use ($member) {
                $expo = $notification->toExpoPush($member);
                $this->assertEquals('community_post', $expo['data']['type']);
                $this->assertContains(ExpoPushChannel::class, $notification->via($member));
                return true;
            }
        );
        Notification::assertNotSentTo($author, CommunityNewPostNotification::class);

        Notification::fake(); // Reset

        // 4. Member likes community post -> Author gets notified
        $postService->toggleLike($member, $community->id, $postId);

        Notification::assertSentTo(
            $author,
            GeneralNotification::class,
            function (GeneralNotification $notification) use ($author) {
                $expo = $notification->toExpoPush($author);
                $this->assertEquals('community_post_like', $expo['data']['type']);
                $this->assertStringContainsString('liked your post', $expo['title']);
                return true;
            }
        );

        Notification::fake(); // Reset

        // 5. Member comments on community post -> Author gets notified
        $commentData = $postService->addComment($member, $community->id, $postId, 'Awesome discussion!');

        Notification::assertSentTo(
            $author,
            GeneralNotification::class,
            function (GeneralNotification $notification) use ($author) {
                $expo = $notification->toExpoPush($author);
                $this->assertEquals('community_post_comment', $expo['data']['type']);
                $this->assertStringContainsString('commented on your post', $expo['title']);
                return true;
            }
        );

        Notification::fake(); // Reset

        // 6. Replier replies to Member's comment -> Member gets notified
        $postService->addComment($replier, $community->id, $postId, 'Totally agree!', $commentData['id']);

        Notification::assertSentTo(
            $member,
            GeneralNotification::class,
            function (GeneralNotification $notification) use ($member) {
                $expo = $notification->toExpoPush($member);
                $this->assertEquals('community_comment_reply', $expo['data']['type']);
                $this->assertStringContainsString('replied to your comment', $expo['title']);
                return true;
            }
        );
    }

    public function test_push_notification_channel_delivers_to_logged_in_and_logged_out_devices(): void
    {
        Http::fake([
            'https://exp.host/--/api/v2/push/send' => Http::response([
                'data' => [
                    ['status' => 'ok', 'id' => 'rec-1'],
                    ['status' => 'ok', 'id' => 'rec-2'],
                ],
            ], 200),
        ]);

        $user = $this->makeUser('Active Multi-Device User');

        // Device 1: Logged in iPhone
        UserDeviceToken::create([
            'user_id' => $user->id,
            'device_id' => 'device-ios-001',
            'token' => 'ExponentPushToken[device1_ios_token]',
            'platform' => 'ios',
            'device_name' => 'iPhone 15 Pro',
            'location_type' => 'cellular',
            'ip_address' => '102.89.34.112',
            'location' => 'London, United Kingdom',
            'is_logged_out' => false,
            'is_active' => true,
        ]);

        // Device 2: Logged out Android (re-engagement enabled)
        UserDeviceToken::create([
            'user_id' => $user->id,
            'device_id' => 'device-android-002',
            'token' => 'ExponentPushToken[device2_android_token]',
            'platform' => 'android',
            'device_name' => 'Pixel 8',
            'location_type' => 'wifi',
            'ip_address' => '192.168.1.50',
            'location' => 'Houston, Texas',
            'is_logged_out' => true,
            'is_active' => true,
        ]);

        // Trigger notification directly via notify()
        $user->notify(new GeneralNotification([
            'title' => 'Special Update',
            'message' => 'Check out new community posts',
            'type' => 'announcement',
        ]));

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://exp.host/--/api/v2/push/send') {
                return false;
            }

            $body = $request->data();
            $tokens = collect($body)->pluck('to')->all();

            return in_array('ExponentPushToken[device1_ios_token]', $tokens, true)
                && in_array('ExponentPushToken[device2_android_token]', $tokens, true);
        });
    }
}
