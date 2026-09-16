<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Models\UserComment;
use App\Models\UserLevel;
use App\Notifications\GeneralNotification;
use Illuminate\Support\Facades\DB;

class CommentService
{
    public $postId;

    public $user;

    public $message;

    public function addComment($postId, User $user, $message, ?string $parentId = null): Comment
    {
        $authUserId = $user->id;

        return DB::transaction(function () use ($authUserId, $postId, $message, $user, $parentId) {
            $effectiveParentId = $parentId;
            $parentComment = null;

            if ($effectiveParentId) {
                $parentComment = Comment::find($effectiveParentId);
                if ($parentComment) {
                    // Flatten nested reply to root parent if parent is already a reply
                    if ($parentComment->parent_id) {
                        $effectiveParentId = $parentComment->parent_id;
                        $parentComment = Comment::find($effectiveParentId) ?? $parentComment;
                    }
                } else {
                    $effectiveParentId = null;
                }
            }

            // 1️⃣ Create the raw comment
            $comment = Comment::create([
                'user_id' => $authUserId,
                'post_id' => $postId,
                'parent_id' => $effectiveParentId,
                'message' => $message,
            ]);

            // 2️⃣ Lock the post to prevent race conditions
            $post = Post::select('id', 'user_id')
                ->whereKey($postId)
                ->lockForUpdate()
                ->firstOrFail();

            $isSelfComment = $authUserId === $post->user_id;

            $type = match (true) {
                $isSelfComment => 'self-comment',
                $user->status === 'SHADOW_BANNED' => 'shadow_banned',
                default => 'comment',
            };

            // 3️⃣ Check if this is the user's first comment
            $isFirstComment = ! UserComment::where([
                'user_id' => $authUserId,
                'post_id' => $postId,
            ])->exists();

            if ($isFirstComment) {

                // 4️⃣ Create a unique comment entry
                UserComment::create([
                    'user_id' => $authUserId,
                    'post_id' => $postId,
                    'is_paid' => false,
                    'amount' => $this->calculateUniqueEarningPerComment($authUserId),
                    'poster_user_id' => $post->user_id,
                    'type' => $type,
                ]);

                // 5️⃣ Atomic increment
                Post::whereKey($postId)->increment('comments');

                // 6️⃣ Notify post owner (skip self-comment)
                if (! $isSelfComment) {
                    $postOwner = User::find($post->user_id);
                    $postOwner?->notify(new GeneralNotification([
                        'title'   => displayName($user->name) . ' commented on your post',
                        'message' => displayName($user->name) . ' commented on your post',
                        'icon'    => 'fa-comment text-primary',
                        'url'     => url('timeline/' . $post->id),
                        'type'    => 'post_comment',
                        'meta'    => [
                            'post_id' => $post->id,
                            'comment_id' => $comment->id,
                        ],
                    ]));
                }

            } else {

                // Non-unique comment
                Post::whereKey($postId)->update([
                    'comment_external' => DB::raw('COALESCE(comment_external, 0) + 1'),
                ]);
            }

            // If this is a reply, notify parent comment's author if it's someone else
            if ($parentComment && $parentComment->user_id && $parentComment->user_id !== $authUserId) {
                if ($parentComment->user_id !== $post->user_id || ! $isFirstComment) {
                    $parentAuthor = User::find($parentComment->user_id);
                    $parentAuthor?->notify(new GeneralNotification([
                        'title'   => displayName($user->name) . ' replied to your comment',
                        'message' => displayName($user->name) . ' replied to your comment on a post',
                        'icon'    => 'fa-reply text-primary',
                        'url'     => url('timeline/' . $post->id),
                        'type'    => 'comment_reply',
                        'meta'    => [
                            'post_id' => $post->id,
                            'comment_id' => $comment->id,
                            'parent_id' => $parentComment->id,
                        ],
                    ]));
                }
            }

            return $comment;
        });
    }

    private function calculateUniqueEarningPerComment(string $userId): float
    {
        $userLevel = UserLevel::where('user_id', $userId)->first();

        if ($userLevel && ($userLevel->plan_name === 'Basic' || $userLevel->plan_name === 'Creator')) {
            return 0.00002;
        } else {
            return 0.0004;
        }
    }
}
