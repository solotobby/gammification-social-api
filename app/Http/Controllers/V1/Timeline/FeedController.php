<?php

namespace App\Http\Controllers\V1\Timeline;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessComment;
use App\Jobs\ProcessPostImage;
use App\Jobs\ProcessPostVideo;
use App\Jobs\ProcessToggleLike;
use App\Jobs\ProcessView;
use App\Models\Post;

use App\Services\HashTagServices;
use App\Services\UserServices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
// use App\Jobs\ProcessPostImage;
// use App\Jobs\ProcessPostVideo;
use App\Models\PostImages;
use App\Models\PostVideo;


class FeedController extends Controller
{

    protected UserServices $userServices;
    protected HashTagServices $hashtagservices;


    public function __construct(UserServices $userServices, HashTagServices $hashtagservices)
    {
        $this->userServices = $userServices;
        $this->hashtagservices = $hashtagservices;
        // $this->middleware('auth');
        // throw new \Exception('Not implemented');
    }


    public function feed()
    {
        try {
            $posts = Post::with(['user:id,username,name'])
                ->with(['video' => function ($q) {
                    $q->where('processing_status', 'completed')
                        ->select(['id', 'post_id', 'path', 'hd_path', 'thumbnail_path', 'duration', 'width', 'height']);
                }])
                ->with(['images' => function ($q) {
                    $q->where('processing_status', 'completed')
                        ->select(['id', 'post_id', 'path', 'thumbnail_path', 'full_path', 'width', 'height']);
                }])
                ->where('status', 'LIVE')
                ->latest('created_at')
                ->select(['id', 'user_id', 'content', 'views', 'likes', 'comments', 'has_video', 'has_images', 'media_status', 'created_at'])
                ->paginate(10);

            $posts->getCollection()->transform(function (Post $post) {
                $post->media = null;

                if ($post->media_status !== 'completed') {
                    return $post;
                }

                if ($post->has_video && $post->video) {
                    $post->media = [
                        'type' => 'video',
                        'sd_url' => $post->video->path,
                        'hd_url' => $post->video->hd_path,
                        'poster_url' => $post->video->thumbnail_path,
                        'duration' => $post->video->duration,
                        'width' => $post->video->width,
                        'height' => $post->video->height,
                    ];
                } elseif ($post->has_images && $post->images->isNotEmpty()) {
                    $post->media = [
                        'type' => 'images',
                        'items' => $post->images->map(fn($img) => [
                            'thumb_url' => $img->thumbnail_path,
                            'medium_url' => $img->path,
                            'full_url' => $img->full_path,
                            'width' => $img->width,
                            'height' => $img->height,
                        ])->values(),
                    ];
                }

                unset($post->video, $post->images); // drop the raw relations, keep the flat `media` block

                return $post;
            });

            return response()->json([
                'success' => true,
                'message' => 'Feeds',
                'data' => $posts,
            ], 200);
        } catch (Throwable $e) {
            Log::error('Failed to load feed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load feeds',
                'error_temp' => $e->getMessage(),
            ], 500);
        }
    }





    public function createPost(Request $request, UserServices $userServices, HashTagServices $hashtagservices)
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
            }

            $level = $this->userServices->activeLevel($user);
            $tier = config("media_tiers.tiers.{$level}", config('media_tiers.tiers.default'));





            $rules = [
                'content' => ['required', 'string'],
                'images' => ['nullable', 'array'],
                'video' => ['nullable', 'file'],
            ];

            if (!in_array($level, ['Creator', 'Influencer'])) {
                $rules['content'][] = 'max:160';
            }

            if ($tier['images']['allowed']) {
                $rules['images'][] = 'max:' . $tier['images']['max'];
                $rules['images.*'] = [
                    'image',
                    'mimes:jpg,jpeg,png,webp',
                    'max:' . config('media_tiers.image.max_upload_kb'),
                ];
            } else {
                $rules['images'][] = 'prohibited';
            }

            if ($tier['video']['allowed']) {
                $rules['video'][] = 'mimes:mp4,mov,webm,avi';
                $rules['video'][] = 'max:' . (config('media_tiers.video.max_upload_mb') * 1024);
            } else {
                $rules['video'][] = 'prohibited';
            }

            $validated = $request->validate($rules);

            if (trim($validated['content']) === '') {
                return response()->json(['success' => false, 'message' => 'You need to write a post'], 422);
            }

            $images = $request->file('images', []);
            $video = $request->file('video');

            if (count($images) > 0 && $video) {
                return response()->json([
                    'success' => false,
                    'message' => 'Post with either images or a video, not both',
                ], 422);
            }

            $content = $this->convertUrlsToLinks(strip_tags($validated['content']));



            if (empty(trim($content))) {
                return response()->json(['success' => false, 'message' => 'Post content cannot be empty'], 422);
            }

            $previousPosts = Post::where('user_id', $user->id)->pluck('content')->toArray();

            if ($this->isSimilar($content, $previousPosts, 4)) {
                return response()->json([
                    'success' => false,
                    'message' => 'This content is too similar to an existing post',
                ], 422);
            }

            [$post, $queued] = DB::transaction(function () use (
                $user,
                $content,
                $images,
                $video,
                $hashtagservices
            ) {
                $status = $user->status === 'ACTIVE' ? 'LIVE' : 'SHADOW_BANNED';
                $hasMedia = (count($images) > 0) || $video;

                $post = Post::create([
                    'user_id' => $user->id,
                    'content' => $content,
                    'unicode' => rand(1000, 9999) . time(),
                    'comment_external' => 0,
                    'status' => $status,
                    'media_status' => $hasMedia ? 'processing' : 'ready',
                ]);

                $hashtagservices->attach($post, $post->content);

                $queued = ['images' => [], 'video' => null];

                foreach ($images as $image) {
                    $localPath = $image->store('queue/images', 'local');

                    $record = PostImages::create([
                        'user_id' => $user->id,
                        'post_id' => $post->id,
                        'path' => $localPath,
                        'processing_status' => 'processing',
                    ]);

                    $queued['images'][] = [
                        'id' => $record->id,
                        'local' => Storage::disk('local')->path($localPath), // was storage_path('app/' . $localPath)
                    ];
                }

                if ($video) {
                    $localPath = $video->store('queue/videos', 'local');

                    $record = PostVideo::create([
                        'id' => (string) Str::uuid(),
                        'path' => $localPath,
                        'user_id' => $user->id,
                        'post_id' => $post->id,
                        'processing_status' => 'processing',
                    ]);

                    $queued['video'] = [
                        'id' => $record->id,
                        'local' => Storage::disk('local')->path($localPath), // was storage_path('app/' . $localPath)
                    ];
                }

                return [$post, $queued];
            });

            foreach ($queued['images'] as $img) {
                ProcessPostImage::dispatch($img['id'], $img['local'], $user->id)->afterCommit();
            }
            if ($queued['video']) {
                ProcessPostVideo::dispatch(
                    $queued['video']['id'],
                    $queued['video']['local'],
                    $user->id,
                    $tier['video']['max_seconds'],
                    $level === 'Influencer',
                )->afterCommit();
            }

            return response()->json([
                'success' => true,
                'message' => $post->media_status === 'processing'
                    ? 'Post created — media is processing and will appear shortly'
                    : 'Post created successfully',
                'data' => [
                    'post_id' => $post->id,
                    'status' => $post->status,
                    'media_status' => $post->media_status,
                ],
            ], 201);
        } catch (Throwable $e) {
            Log::error('Failed to post content', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'There was an error while creating post',
                'error_temp' => $e->getMessage(),
            ], 500);
        }
    }

    private function convertUrlsToLinks($text)
    {
        $pattern = '/\b(?:https?:\/\/|www\.)\S+\b/';
        $replacement = '<a href="$0" target="_blank" rel="noopener noreferrer">$0</a>';
        return preg_replace($pattern, $replacement, $text);
    }

    private function isSimilar($newData, $existingData, $threshold = 4)
    {
        $normalizedNewData = $this->normalizeText($newData);

        foreach ($existingData as $data) {
            $normalizedData = $this->normalizeText($data);
            $levenshteinDistance = levenshtein($normalizedNewData, $normalizedData);

            if ($levenshteinDistance <= $threshold) {
                return true;
            }
        }

        return false;
    }

    private function normalizeText($text)
    {

        $text = preg_replace('/[^\w\s]/', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return strtolower(trim($text));
    }

    public function toggleLikePost(Request $request)
    {
        $validated = $request->validate([
            'post_id' => ['required', 'string'],
        ]);

        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
            }

            $post = Post::findOrFail($validated['post_id']);


            ProcessToggleLike::dispatch($post->id, $post->unicode, $user)->afterCommit();

            return response()->json([
                'success' => true,
                'message' => 'Like toggle queued',
            ], 202);
        } catch (Throwable $e) {
            Log::error('Failed to toggle like on post', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'There was an error while toggling like on post',
                'error_temp' => $e->getMessage(),
            ], 500);
        }
    }

    public function postComment(Request $request)
    {
        $validated = $request->validate([
            'post_id' => ['required', 'string'],
            'comment' => ['required', 'string', 'max:500'],
        ]);


        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
            }

            $post = Post::findOrFail($request->input('post_id'));

            if (!$post) {
                return response()->json(['success' => false, 'message' => 'Post not found'], 404);
            }

            ProcessComment::dispatch($post->id, $user, $validated['comment']);

            return response()->json([
                'success' => true,
                'message' => 'Comment posted successfully',
            ], 202);

        } catch (Throwable $e) {
            Log::error('Failed to post comment', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'There was an error while posting comment',
                'error_temp' => $e->getMessage(),
            ], 500);
        }
        // Implement the logic for posting a comment here

    }

    public function viewPost(Request $request, $postId)
    {
        try {
             $user = $request->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
            }
            $post = Post::with(['user:id,username,name'])
                ->with(['video' => function ($q) {
                    $q->where('processing_status', 'completed')
                        ->select(['id', 'post_id', 'path', 'hd_path', 'thumbnail_path', 'duration', 'width', 'height']);
                }])
                ->with(['images' => function ($q) {
                    $q->where('processing_status', 'completed')
                        ->select(['id', 'post_id', 'path', 'thumbnail_path', 'full_path', 'width', 'height']);
                }])
                ->where('status', 'LIVE')
                ->where('id', $postId)
                ->firstOrFail();
                
                $post->increment('views');


                // ProcessView::dispatch($post, $user)->afterCommit();

            return response()->json([
                'success' => true,
                'message' => 'Post details',
                'data' => $post,
            ], 200);
        } catch (Throwable $e) {
            Log::error('Failed to load post details', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load post details',
                'error_temp' => $e->getMessage(),
            ], 500);
        }
    }
}
