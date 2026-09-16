<?php

namespace App\Services;

use App\Models\Follow;
use App\Models\User;
use App\Notifications\GeneralNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class FollowService
{
    /**
     * Toggle follow state between two users. Returns fresh state + counts
     * so the caller never has to guess or trust a stale frontend flag.
     *
     * @return array{following: bool, followers_count: int, following_count: int}
     */
    public function toggle(User $authUser, User $targetUser, bool $notifyOnUnfollow = false): array
    {
        if ($authUser->id === $targetUser->id) {
            throw new \InvalidArgumentException('A user cannot follow themselves.');
        }

        $result = DB::transaction(function () use ($authUser, $targetUser) {
            $follow = Follow::where('follower_id', $authUser->id)
                ->where('following_id', $targetUser->id)
                ->lockForUpdate()
                ->first();

            return $follow
                ? $this->unfollow($authUser, $targetUser, $follow)
                : $this->follow($authUser, $targetUser);
        });

        // $this->clearUserFeedCache($authUser->id);
        if ($result['following']) {
            $targetUser->notify(new GeneralNotification([
                'title'   => displayName($authUser->name) . ' started following you',
                'message' => displayName($authUser->name) . ' started following you',
                'icon'    => 'fa-user-plus text-primary',
                'url'     => url('profile/' . $authUser->username),
                'type'    => 'user_follow',
                'meta'    => [
                    'follower_id'       => $authUser->id,
                    'follower_username' => $authUser->username,
                    'follower_avatar'   => $authUser->avatar,
                ],
            ]));
        } else {
            $targetUser->notify(new GeneralNotification([
                'title'   => displayName($authUser->name) . ' unfollowed you',
                'message' => displayName($authUser->name) . ' unfollowed you',
                'icon'    => 'fa-user-minus text-muted',
                'url'     => url('profile/' . $authUser->username),
                'type'    => 'user_unfollow',
                'meta'    => [
                    'unfollower_id'       => $authUser->id,
                    'unfollower_username' => $authUser->username,
                    'unfollower_avatar'   => $authUser->avatar,
                ],
            ]));
        }

        return $result;
    }

    protected function follow(User $authUser, User $targetUser): array
    {
        try {
            Follow::create([
                'follower_id' => $authUser->id,
                'following_id' => $targetUser->id,
            ]);
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            // Already following — do not inflate counts.
            return $this->buildResult($authUser, $targetUser, following: true);
        }

        User::whereKey($authUser->id)->increment('following');
        User::whereKey($targetUser->id)->increment('followers');

        return $this->buildResult($authUser, $targetUser, following: true);
    }

    protected function unfollow(User $authUser, User $targetUser, Follow $follow): array
    {
        $follow->delete();

        User::whereKey($authUser->id)->where('following', '>', 0)->decrement('following');
        User::whereKey($targetUser->id)->where('followers', '>', 0)->decrement('followers');

        return $this->buildResult($authUser, $targetUser, following: false);
    }

    /**
     * Explicit per-user shape — no ambiguity about whose count is whose.
     */
    protected function buildResult(User $authUser, User $targetUser, bool $following): array
    {
        $freshAuth = $authUser->fresh();
        // $freshTarget = $targetUser->fresh();

        return [
            'following' => $following,
            'auth_user' => [
                'id' => $freshAuth->id,
                'following_count' => $freshAuth->following,
                'followers_count' => $freshAuth->followers,
            ],
            // 'target_user' => [
            //     'id' => $freshTarget->id,
            //     'following_count' => $freshTarget->following,
            //     'followers_count' => $freshTarget->followers,
            // ],
        ];
    }
    // protected function sendNotification(User $recipient, User $actor, bool $following): void
    // {
    //     $recipient->notify(new GeneralNotification([
    //         'title' => displayName($actor->name) . ($following ? ' followed you' : ' unfollowed you'),
    //         'message' => displayName($actor->name) . ($following ? ' followed you' : ' unfollowed you'),
    //         'icon' => $following ? 'fa-user-plus text-primary' : 'fa-user-minus text-primary',
    //         'url' => url('profile/' . $actor->username),
    //     ]));
    // }

    protected function isUniqueViolation(QueryException $e): bool
    {
        // 23000 = MySQL/PostgreSQL integrity constraint violation SQLSTATE
        return $e->getCode() === '23000';
    }

    protected function clearUserFeedCache(string $userId): void
    {
        // Wire this up to whatever your existing feed cache key pattern is.
        // e.g. Cache::forget("user_feed:{$userId}");
    }

    /**
     * Get paginated followers of a target user.
     */
    public function getFollowers(User $targetUser, ?User $viewer = null, int $perPage = 20): LengthAwarePaginator
    {
        $paginator = Follow::query()
            ->where('following_id', $targetUser->id)
            ->with(['followers.profile'])
            ->latest('created_at')
            ->paginate($perPage);

        $followerUserIds = $paginator->getCollection()->pluck('follower_id')->filter()->all();

        $viewerFollowingIds = ($viewer && ! empty($followerUserIds))
            ? Follow::where('follower_id', $viewer->id)
                ->whereIn('following_id', $followerUserIds)
                ->pluck('following_id')
                ->flip()
            : collect();

        $paginator->getCollection()->transform(function (Follow $follow) use ($viewer, $viewerFollowingIds) {
            $user = $follow->followers;
            if (! $user) {
                return null;
            }

            return [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'avatar' => $user->avatar,
                'about' => $user->profile?->about,
                'is_following' => $viewer ? isset($viewerFollowingIds[$user->id]) : false,
                'is_me' => $viewer ? $viewer->id === $user->id : false,
                'followed_at' => $follow->created_at?->toIso8601String(),
            ];
        });

        $paginator->setCollection($paginator->getCollection()->filter()->values());

        return $paginator;
    }

    /**
     * Get paginated users that a target user is following.
     */
    public function getFollowing(User $targetUser, ?User $viewer = null, int $perPage = 20): LengthAwarePaginator
    {
        $paginator = Follow::query()
            ->where('follower_id', $targetUser->id)
            ->with(['following.profile'])
            ->latest('created_at')
            ->paginate($perPage);

        $followingUserIds = $paginator->getCollection()->pluck('following_id')->filter()->all();

        $viewerFollowingIds = ($viewer && ! empty($followingUserIds))
            ? Follow::where('follower_id', $viewer->id)
                ->whereIn('following_id', $followingUserIds)
                ->pluck('following_id')
                ->flip()
            : collect();

        $paginator->getCollection()->transform(function (Follow $follow) use ($viewer, $viewerFollowingIds) {
            $user = $follow->following;
            if (! $user) {
                return null;
            }

            return [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'avatar' => $user->avatar,
                'about' => $user->profile?->about,
                'is_following' => $viewer ? isset($viewerFollowingIds[$user->id]) : false,
                'is_me' => $viewer ? $viewer->id === $user->id : false,
                'followed_at' => $follow->created_at?->toIso8601String(),
            ];
        });

        $paginator->setCollection($paginator->getCollection()->filter()->values());

        return $paginator;
    }
}
