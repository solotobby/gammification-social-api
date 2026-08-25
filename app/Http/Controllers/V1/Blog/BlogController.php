<?php

namespace App\Http\Controllers\V1\Blog;

use App\Http\Controllers\Controller;
use App\Services\BlogService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class BlogController extends Controller
{
    public function __construct(protected BlogService $blogService) {}

    /**
     * GET /v1/blogs — paginated published blogs.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'category' => ['sometimes', 'uuid', 'exists:blog_categories,id'],
            'q' => ['sometimes', 'string', 'min:1', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        try {
            $blogs = $this->blogService->list(
                $validated,
                (int) ($validated['per_page'] ?? BlogService::PER_PAGE),
            );

            return response()->json([
                'success' => true,
                'message' => 'Blogs',
                'data' => $blogs,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to load blogs', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to load blogs',
            ], 500);
        }
    }

    /**
     * GET /v1/blogs/{slug} — blog details + similar posts by category.
     */
    public function show(string $slug)
    {
        try {
            $payload = $this->blogService->getBySlug($slug);

            return response()->json([
                'success' => true,
                'message' => 'Blog details',
                'data' => $payload,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Blog not found',
            ], 404);
        } catch (Throwable $e) {
            Log::error('Failed to load blog', [
                'slug' => $slug,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to load blog',
            ], 500);
        }
    }
}
