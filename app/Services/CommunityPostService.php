<?php

namespace App\Services;

use App\Models\Community;
use App\Models\CommunityPost;
use App\Models\CommunityPostComment;
use App\Models\CommunityPostLike;
use App\Models\CommunityPostView;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class CommunityPostService
{
    public const MAX_WORDS = 100000;

    public const MAX_MEDIA = 4;

    public const POSTS_PER_PAGE = 7;

    public const COMMENTS_PREVIEW = 3;

    public const COMMENTS_PER_PAGE = 10;

    public const MAX_COMMENT_LENGTH = 500;

    public function __construct(protected CommunityService $communityService) {}

    public function listFeed(User $user, string $communityId, int $perPage = self::POSTS_PER_PAGE): LengthAwarePaginator
    {
        $community = $this->findCommunity($communityId);
        $this->communityService->assertCanViewFeed($community, $user);

        $paginator = CommunityPost::query()
            ->where('community_id', $community->id)
            ->with([
                'user:id,username,name,avatar',
                'media',
                'comments' => fn ($q) => $q
                    ->with('user:id,username,name,avatar')
                    ->latest()
                    ->limit(self::COMMENTS_PREVIEW),
            ])
            ->latest()
            ->paginate($perPage);

        $paginator->getCollection()->transform(
            fn (CommunityPost $post) => $this->formatPost($post, $user, includeCommentsPreview: true),
        );

        return $paginator;
    }

    public function listComments(
        User $user,
        string $communityId,
        string $postId,
        int $perPage = self::COMMENTS_PER_PAGE,
    ): LengthAwarePaginator {
        $community = $this->findCommunity($communityId);
        $this->communityService->assertCanViewFeed($community, $user);

        $post = $this->findPost($community, $postId);

        $paginator = CommunityPostComment::query()
            ->where('community_post_id', $post->id)
            ->with('user:id,username,name,avatar')
            ->latest()
            ->paginate($perPage);

        $paginator->getCollection()->transform(
            fn (CommunityPostComment $comment) => $this->formatComment($comment),
        );

        return $paginator;
    }

    public function toggleLike(User $user, string $communityId, string $postId): array
    {
        $community = $this->findCommunity($communityId);
        $this->communityService->assertCanInteract($community, $user);

        $post = $this->findPost($community, $postId);

        $liked = DB::transaction(function () use ($post, $user) {
            $existing = CommunityPostLike::query()
                ->where('community_post_id', $post->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existing) {
                $existing->delete();
                $post->decrement('likes_count');

                return false;
            }

            CommunityPostLike::create([
                'community_post_id' => $post->id,
                'user_id' => $user->id,
            ]);
            $post->increment('likes_count');

            return true;
        });

        $post->refresh();

        return [
            'liked' => $liked,
            'likes_count' => (int) $post->likes_count,
        ];
    }

    public function addComment(User $user, string $communityId, string $postId, string $content): array
    {
        $community = $this->findCommunity($communityId);
        $this->communityService->assertCanInteract($community, $user);

        $post = $this->findPost($community, $postId);

        $content = trim($content);

        if ($content === '') {
            throw new InvalidArgumentException('Comment cannot be empty.');
        }

        if (mb_strlen($content) > self::MAX_COMMENT_LENGTH) {
            throw new InvalidArgumentException('Comment cannot exceed '.self::MAX_COMMENT_LENGTH.' characters.');
        }

        $comment = DB::transaction(function () use ($post, $user, $content) {
            $comment = CommunityPostComment::create([
                'community_post_id' => $post->id,
                'user_id' => $user->id,
                'content' => $content,
            ]);

            $post->increment('comments_count');

            return $comment;
        });

        $comment->load('user:id,username,name,avatar');

        return $this->formatComment($comment);
    }

    public function recordView(User $user, string $communityId, string $postId, ?string $ipAddress = null): array
    {
        $community = $this->findCommunity($communityId);
        $this->communityService->assertCanViewFeed($community, $user);

        $post = $this->findPost($community, $postId);

        $view = CommunityPostView::firstOrCreate(
            ['community_post_id' => $post->id, 'user_id' => $user->id],
            ['ip_address' => $ipAddress],
        );

        if ($view->wasRecentlyCreated) {
            $post->increment('views_count');
            $post->refresh();
        }

        return [
            'recorded' => $view->wasRecentlyCreated,
            'views_count' => (int) $post->views_count,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<int, UploadedFile>  $mediaFiles
     */
    public function create(User $user, string $communityId, array $validated, array $mediaFiles = []): array
    {
        $community = Community::query()
            ->whereNull('archived_at')
            ->find($communityId);

        if (! $community) {
            throw new ModelNotFoundException('Community not found.');
        }

        $this->assertCanPost($community, $user);

        $content = trim((string) ($validated['content'] ?? ''));

        if ($content !== '' && countSocialWords($content) > self::MAX_WORDS) {
            throw new InvalidArgumentException(
                'Community posts cannot exceed '.number_format(self::MAX_WORDS).' words.',
            );
        }

        $post = DB::transaction(function () use ($user, $community, $content, $mediaFiles) {
            $post = $community->posts()->create([
                'user_id' => $user->id,
                'content' => $content !== '' ? $content : null,
            ]);

            foreach ($mediaFiles as $index => $file) {
                if (! $file instanceof UploadedFile) {
                    continue;
                }

                $isVideo = str_starts_with((string) $file->getMimeType(), 'video/');
                $path = $file->store('communities/'.$community->id.'/posts', 'spaces');

                $post->media()->create([
                    'path' => $path,
                    'type' => $isVideo ? 'video' : 'image',
                    'sort' => $index,
                ]);
            }

            return $post;
        });

        $post->load(['user:id,username,name,avatar', 'media']);

        return $this->formatPost($post, $user);
    }

    public function formatPost(CommunityPost $post, ?User $viewer = null, bool $includeCommentsPreview = false): array
    {
        $viewerId = $viewer?->id;

        $data = [
            'id' => $post->id,
            'community_id' => $post->community_id,
            'content' => $post->content,
            'word_count' => countSocialWords((string) ($post->content ?? '')),
            'likes_count' => (int) ($post->likes_count ?? 0),
            'comments_count' => (int) ($post->comments_count ?? 0),
            'views_count' => (int) ($post->views_count ?? 0),
            'is_liked' => $viewerId ? $post->isLikedBy($viewerId) : false,
            'user' => $post->user ? [
                'id' => $post->user->id,
                'username' => $post->user->username,
                'name' => $post->user->name,
                'avatar' => $post->user->avatar,
            ] : null,
            'media' => $post->media->map(fn ($item) => [
                'id' => $item->id,
                'type' => $item->type,
                'url' => Storage::disk('spaces')->url($item->path),
                'sort' => (int) $item->sort,
            ])->values()->all(),
            'created_at' => $post->created_at?->toIso8601String(),
        ];

        if ($includeCommentsPreview) {
            $preview = $post->relationLoaded('comments')
                ? $post->comments
                : collect();

            $data['comments'] = [
                'preview' => $preview->map(fn (CommunityPostComment $comment) => $this->formatComment($comment))->values()->all(),
                'total' => (int) ($post->comments_count ?? 0),
                'has_more' => (int) ($post->comments_count ?? 0) > self::COMMENTS_PREVIEW,
            ];
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function formatComment(CommunityPostComment $comment): array
    {
        return [
            'id' => $comment->id,
            'content' => $comment->content,
            'user' => $comment->user ? [
                'id' => $comment->user->id,
                'username' => $comment->user->username,
                'name' => $comment->user->name,
                'avatar' => $comment->user->avatar,
            ] : null,
            'created_at' => $comment->created_at?->toIso8601String(),
        ];
    }

    private function findCommunity(string $communityId): Community
    {
        $community = Community::query()
            ->whereNull('archived_at')
            ->find($communityId);

        if (! $community) {
            throw new ModelNotFoundException('Community not found.');
        }

        return $community;
    }

    private function findPost(Community $community, string $postId): CommunityPost
    {
        $post = CommunityPost::query()
            ->where('community_id', $community->id)
            ->where('id', $postId)
            ->first();

        if (! $post) {
            throw new ModelNotFoundException('Post not found.');
        }

        return $post;
    }

    private function assertCanPost(Community $community, User $user): void
    {
        if ($community->isArchived()) {
            throw new InvalidArgumentException('This community is archived.');
        }

        if (! $this->communityService->isMemberPublic($community, $user->id)) {
            throw new InvalidArgumentException('Only members can post in this community.');
        }
    }
}
