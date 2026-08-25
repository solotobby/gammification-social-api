<?php

namespace App\Services;

use App\Models\Blog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class BlogService
{
    public const PER_PAGE = 10;

    public const SIMILAR_LIMIT = 5;

    public function list(array $filters = [], int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $query = $this->publishedQuery()
            ->with('blogCategory:id,name');

        if (! empty($filters['category'])) {
            $query->where('blog_category_id', $filters['category']);
        }

        if (! empty($filters['q'])) {
            $term = '%' . addcslashes(trim($filters['q']), '%_\\') . '%';
            $query->where(function (Builder $q) use ($term) {
                $q->where('title', 'like', $term)
                    ->orWhere('excerpt', 'like', $term);
            });
        }

        return $query
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->through(fn (Blog $blog) => $this->presentSummary($blog));
    }

    /**
     * @return array{blog: array<string, mixed>, similar: array<int, array<string, mixed>>}
     */
    public function getBySlug(string $slug, bool $incrementViews = true): array
    {
        $blog = $this->publishedQuery()
            ->with('blogCategory:id,name')
            ->where('slug', $slug)
            ->firstOrFail();

        if ($incrementViews) {
            $blog->increment('views');
        }

        return [
            'blog' => $this->presentDetail($blog),
            'similar' => $this->similarBlogs($blog),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function similarBlogs(Blog $blog): array
    {
        $select = [
            'id',
            'title',
            'slug',
            'excerpt',
            'cover_image',
            'published_at',
            'created_at',
            'blog_category_id',
            'views',
        ];

        $similar = Blog::query()
            ->with('blogCategory:id,name')
            ->where('status', 'PUBLISHED')
            ->where('id', '!=', $blog->id)
            ->when(
                $blog->blog_category_id,
                fn (Builder $query) => $query->where('blog_category_id', $blog->blog_category_id)
            )
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->limit(self::SIMILAR_LIMIT)
            ->get($select);

        if ($similar->count() < self::SIMILAR_LIMIT) {
            $excludeIds = $similar->pluck('id')->push($blog->id)->all();

            $fillers = Blog::query()
                ->with('blogCategory:id,name')
                ->where('status', 'PUBLISHED')
                ->whereNotIn('id', $excludeIds)
                ->orderByDesc('published_at')
                ->orderByDesc('created_at')
                ->limit(self::SIMILAR_LIMIT - $similar->count())
                ->get($select);

            $similar = $similar->concat($fillers)->values();
        }

        return $similar
            ->map(fn (Blog $item) => $this->presentSummary($item))
            ->all();
    }

    private function publishedQuery(): Builder
    {
        return Blog::query()->where('status', 'PUBLISHED');
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSummary(Blog $blog): array
    {
        return [
            'id' => $blog->id,
            'title' => $blog->title,
            'slug' => $blog->slug,
            'excerpt' => $blog->excerpt,
            'cover_image' => $blog->cover_image,
            'cover_url' => $blog->cover_url,
            'views' => (int) $blog->views,
            'published_at' => $blog->published_at,
            'created_at' => $blog->created_at,
            'category' => $blog->blogCategory ? [
                'id' => $blog->blogCategory->id,
                'name' => $blog->blogCategory->name,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(Blog $blog): array
    {
        return array_merge($this->presentSummary($blog), [
            'content' => $blog->content,
        ]);
    }
}
