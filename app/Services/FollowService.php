<?php

namespace App\Services;

use App\Models\Follow;
use App\Models\User;
use App\Notifications\GeneralNotification;
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
        // $this->clearUserFeedCache($targetUser->id);

        // if ($result['following']) {
        //     $this->sendNotification($targetUser, $authUser, following: true);
        // } elseif ($notifyOnUnfollow) {
        //     $this->sendNotification($targetUser, $authUser, following: false);
        // }

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
}