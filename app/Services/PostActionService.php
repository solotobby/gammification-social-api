<?php

namespace App\Services;

use App\Models\HiddenPost;
use App\Models\Post;
use App\Models\PostReport;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class PostActionService
{
    /**
     * @return array{hidden: true, post_id: string}
     */
    public function hide(User $user, Post $post): array
    {
        if ($user->id === $post->user_id) {
            throw new \InvalidArgumentException('You cannot hide your own post');
        }

        if ($post->status !== 'LIVE') {
            throw new \InvalidArgumentException('This post cannot be hidden');
        }

        DB::transaction(function () use ($user, $post) {
            try {
                HiddenPost::firstOrCreate([
                    'user_id' => $user->id,
                    'post_id' => $post->id,
                ]);
            } catch (QueryException $e) {
                if (! $this->isUniqueViolation($e)) {
                    throw $e;
                }
            }
        });

        return [
            'hidden' => true,
            'post_id' => $post->id,
        ];
    }

    /**
     * @return array{reported: true, post_id: string, already_reported: bool}
     */
    public function report(User $user, Post $post, ?string $reason = null): array
    {
        if ($user->id === $post->user_id) {
            throw new \InvalidArgumentException('You cannot report your own post');
        }

        if ($post->status !== 'LIVE') {
            throw new \InvalidArgumentException('This post cannot be reported');
        }

        $reason = $reason !== null ? trim($reason) : null;
        if ($reason === '') {
            $reason = null;
        }

        $alreadyReported = PostReport::query()
            ->where('user_id', $user->id)
            ->where('post_id', $post->id)
            ->exists();

        DB::transaction(function () use ($user, $post, $reason) {
            try {
                PostReport::firstOrCreate(
                    [
                        'user_id' => $user->id,
                        'post_id' => $post->id,
                    ],
                    [
                        'reason' => $reason,
                        'status' => PostReport::STATUS_PENDING,
                    ],
                );
            } catch (QueryException $e) {
                if (! $this->isUniqueViolation($e)) {
                    throw $e;
                }
            }
        });

        return [
            'reported' => true,
            'post_id' => $post->id,
            'already_reported' => $alreadyReported,
        ];
    }

    protected function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
