<?php

namespace App\Http\Controllers\V1\Timeline;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostImages;
use App\Services\HashTagServices;
use App\Services\UserServices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;


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
            $post = Post::with(['user:id,username,name'])
                ->where('status', 'LIVE')->latest('created_at')
                ->select(['id', 'user_id', 'content', 'views', 'likes', 'comments', 'has_video', 'has_images', 'created_at'])
                ->paginate(5);

            return response()->json([
                'success' => true,
                'message' => 'Feeds',
                'data' => $post
            ], 200);
        } catch (Throwable $e) {

            Log::error('Failed to load feed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                // 'user_id' => optional($request->user())->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load feeds',
                'error_temp' => $e->getMessage(),
            ], 500);
        }
        // return ['title', 'Descriptoin'];
    }

    public function createPost(Request $request, UserServices $userServices, HashTagServices $hashtagservices)
    {


        try {


            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }




            $level =  $this->userServices->activeLevel($user); //get current user active Level

            $rules = [
                'content' => [
                    'required',
                    'string'
                ],
                'images' => [
                    'nullable',
                    'array'
                ]
            ];


            if (!in_array($level, ['Creator', 'Influencer'])) {

                $rules['content'][] = 'max:160';

                $rules['images'][] = 'prohibited';
            } else {

                $rules['images'][] = 'max:4';

                $rules['images.*'] = [
                    'image',
                    'max:2048'
                ];
            }



            $validated = $request->validate($rules);

            if ($validated['content'] == '') {
                return response()->json([
                    'success' => false,
                    'message' => 'You need to write a post'
                ], 422);
            }

            $maxImages = match ($level) {

                'Creator' => 1,

                'Influencer' => 4,

                default => 0,
            };

            $images = $request->file('images', []);



            if ($maxImages === 0 && count($images)) {

                return response()->json([
                    'success' => false,
                    'message' => 'You are not allowed to upload images'
                ], 422);
            }


            if (count($images) > $maxImages) {

                return response()->json([
                    'success' => false,
                    'message' => "Maximum {$maxImages} images allowed"
                ], 422);
            }

            $content = $this->convertUrlsToLinks(
                strip_tags($validated['content'])
            );

            if (empty(trim($content))) {

                return response()->json([
                    'success' => false,
                    'message' => 'Post content cannot be empty'
                ], 422);
            }


            $previousPosts = Post::where(
                'user_id',
                $user->id
            )
                ->pluck('content')
                ->toArray();


            if ($this->isSimilar($content, $previousPosts, 4)) {
                return response()->json([
                    'success' => false,
                    'message' => 'This content is too similar to an existing post'
                ], 422);
            }


            return DB::transaction(function () use (
                $user,
                $content,
                $images,
                $level,
                $hashtagservices
            ) {


                /**
                 * Shadow ban handling
                 */
                $status =
                    $user->status === 'ACTIVE'
                    ? 'LIVE'
                    : 'SHADOW_BANNED';




                /**
                 * Create Post
                 */
                $post = Post::create([
                    'user_id' => $user->id,
                    'content' => $content,
                    'unicode' => rand(1000, 9999) . time(),
                    'comment_external' => 0,
                    'status' => $status

                ]);

                /**
                 * Attach hashtags
                 */
                $hashtagservices->attach(
                    $post,
                    $post->content
                );

                /**
                 * Upload images
                 */
                foreach ($images as $image) {


                    $path = Storage::disk('spaces')
                        ->putFileAs(
                            'payhankey_media/images',
                            $image,
                            Str::uuid()
                                . '-'
                                . $user->id,
                            'public'
                        );



                    $url =
                        config('filesystems.disks.spaces.url')
                        . '/'
                        . $path;

                    PostImages::create([

                        'user_id' => $user->id,

                        'post_id' => $post->id,

                        'path' => $url

                    ]);
                }

                return response()->json([

                    'success' => true,

                    'message' => 'Post created successfully',

                    'data' => [
                        'post_id' => $post->id,
                        'status' => $post->status,
                    ]

                ], 201);
            });
        } catch (Throwable $e) {

            Log::error('Failed to post content', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                // 'user_id' => optional($request->user())->id,
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
}
