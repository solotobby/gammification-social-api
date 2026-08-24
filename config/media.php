<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Staging / cache
    |--------------------------------------------------------------------------
    */

    'staging_path' => env('MEDIA_STAGING_PATH', 'video-staging'),
    'upload_cache_ttl' => (int) env('MEDIA_UPLOAD_CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Image limits
    |--------------------------------------------------------------------------
    */

    'image_max_kb' => (int) env('MEDIA_IMAGE_MAX_KB', 10240),

    'image' => [
        'max_width' => (int) env('MEDIA_IMAGE_MAX_WIDTH', 2048),
        'max_height' => (int) env('MEDIA_IMAGE_MAX_HEIGHT', 2048),
        'quality' => (int) env('MEDIA_IMAGE_QUALITY', 82),
    ],

    /*
    |--------------------------------------------------------------------------
    | Video limits by plan (KB / seconds)
    |--------------------------------------------------------------------------
    */

    'video_max_kb' => [
        'Creator' => (int) env('MEDIA_VIDEO_MAX_KB_CREATOR', 1048576),
        'Influencer' => (int) env('MEDIA_VIDEO_MAX_KB_INFLUENCER', 1048576),
    ],

    'video_max_seconds' => [
        'Creator' => (int) env('MEDIA_VIDEO_MAX_SECONDS_CREATOR', 0),
        'Influencer' => (int) env('MEDIA_VIDEO_MAX_SECONDS_INFLUENCER', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Adaptive MP4 variants (high / medium / low)
    |--------------------------------------------------------------------------
    */

    'video_variants' => [
        'high' => [
            'file' => 'high.mp4',
            'width' => 1080,
            'crf' => '20',
            'preset' => 'fast',
            'audio' => '128k',
        ],
        'medium' => [
            'file' => 'medium.mp4',
            'width' => 720,
            'crf' => '23',
            'preset' => 'fast',
            'audio' => '96k',
        ],
        'low' => [
            'file' => 'low.mp4',
            'width' => 480,
            'crf' => '28',
            'preset' => 'veryfast',
            'audio' => '64k',
        ],
    ],

];
