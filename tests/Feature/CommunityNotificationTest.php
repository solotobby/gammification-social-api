<?php

namespace Tests\Feature;

use App\Jobs\SendCommunityNewPostNotificationJob;
use App\Models\Community;
use App\Models\CommunityCategory;
use App\Models\User;
use App\Notifications\CommunityMemberJoinedNotification;
use App\Notifications\CommunityNewPostNotification;
use App\Services\CommunityMembershipService;
use App\Services\CommunityPostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommunityNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function createUser(): User
    {
        return User::factory()->create([
            'username' => 'user_' . Str::lower(Str::random(10)),
            'referral_code' => 'REF' . Str::upper(Str::random(6)),
        ]);
    }

    public function test_owner_receives_database_and_mail_notification_when_member_joins(): void
    {
        Notification::fake();

        $owner = $this->createUser();
        $member = $this->createUser();

        $category = CommunityCategory::create([
            'name' => 'General',
            'slug' => 'general-' . Str::random(5),
        ]);

        $community = Community::create([
            'user_id' => $owner->id,
            'community_categories_id' => $category->id,
            'name' => 'Test Community',
            'slug' => 'test-community-' . Str::random(6),
            'description' => 'Test description',
            'type' => 'public',
        ]);

        $service = new CommunityMembershipService();
        $service->attachMember($community, $member->id);

        Notification::assertSentTo(
            $owner,
            CommunityMemberJoinedNotification::class,
            function (CommunityMemberJoinedNotification $notification) use ($owner) {
                $channels = $notification->via($owner);
                $this->assertContains('database', $channels);
                $this->assertContains('mail', $channels);
                $this->assertContains(\App\Notifications\Channels\ExpoPushChannel::class, $channels);

                $dbData = $notification->toDatabase($owner);
                $this->assertEquals('community_join', $dbData['type']);
                $this->assertArrayHasKey('meta', $dbData);
                return true;
            }
        );
    }

    public function test_owner_joining_own_community_does_not_trigger_notification(): void
    {
        Notification::fake();

        $owner = $this->createUser();
        $category = CommunityCategory::create([
            'name' => 'General',
            'slug' => 'general-' . Str::random(5),
        ]);

        $community = Community::create([
            'user_id' => $owner->id,
            'community_categories_id' => $category->id,
            'name' => 'Owner Community',
            'slug' => 'owner-community-' . Str::random(6),
            'description' => 'Test description',
            'type' => 'public',
        ]);

        $service = new CommunityMembershipService();
        $service->attachMember($community, $owner->id, 'owner');

        Notification::assertNothingSent();
    }

    public function test_members_receive_database_notification_only_when_new_post_published(): void
    {
        Notification::fake();

        $owner = $this->createUser();
        $author = $this->createUser();
        $member = $this->createUser();

        $category = CommunityCategory::create([
            'name' => 'General',
            'slug' => 'general-' . Str::random(5),
        ]);

        $community = Community::create([
            'user_id' => $owner->id,
            'community_categories_id' => $category->id,
            'name' => 'Post Community',
            'slug' => 'post-community-' . Str::random(6),
            'description' => 'Test description',
            'type' => 'public',
        ]);

        $membershipService = new CommunityMembershipService();
        $membershipService->attachMember($community, $owner->id, 'owner');
        $membershipService->attachMember($community, $author->id);
        $membershipService->attachMember($community, $member->id);

        Notification::fake(); // Reset after membership attaches

        $postService = app(CommunityPostService::class);
        $result = $postService->create($author, $community->id, [
            'content' => 'Exciting news for everyone in the community!',
        ]);

        $postId = $result['post']['id'];

        (new SendCommunityNewPostNotificationJob($postId))->handle();

        // Member should receive database and expo push notification (no email)
        Notification::assertSentTo(
            $member,
            CommunityNewPostNotification::class,
            function (CommunityNewPostNotification $notification) use ($member) {
                $channels = $notification->via($member);
                $this->assertContains('database', $channels);
                $this->assertContains(\App\Notifications\Channels\ExpoPushChannel::class, $channels);
                $this->assertNotContains('mail', $channels);

                $dbData = $notification->toDatabase($member);
                $this->assertEquals('community_post', $dbData['type']);
                $this->assertArrayHasKey('meta', $dbData);
                return true;
            }
        );

        // Author should NEVER receive notification of their own post
        Notification::assertNotSentTo(
            $author,
            CommunityNewPostNotification::class
        );
    }
}
