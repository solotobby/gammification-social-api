<?php

namespace App\Services;

use App\Models\Post;
use App\Models\PostBookmark;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class BookmarkService
{
    /**
     * @return array{bookmarked: bool, post_id: string}
     */
    public function toggle(User $user, Post $post): array
    {
        if ($user->id === $post->user_id) {
            throw new \InvalidArgumentException('You cannot bookmark your own post');
        }

        if ($post->status !== 'LIVE') {
            throw new \InvalidArgumentException('This post cannot be bookmarked');
        }

        return DB::transaction(function () use ($user, $post) {
            $bookmark = PostBookmark::query()
                ->where('user_id', $user->id)
                ->where('post_id', $post->id)
                ->lockForUpdate()
                ->first();

            if ($bookmark) {
                $bookmark->delete();

                return [
                    'bookmarked' => false,
                    'post_id' => $post->id,
                ];
            }

            try {
                PostBookmark::create([
                    'user_id' => $user->id,
                    'post_id' => $post->id,
                ]);
            } catch (QueryException $e) {
                if (! $this->isUniqueViolation($e)) {
                    throw $e;
                }
            }

            return [
                'bookmarked' => true,
                'post_id' => $post->id,
            ];
        });
    }

    protected function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
