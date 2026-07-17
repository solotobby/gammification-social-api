<?php

return [

    'tiers' => [
        'Creator' => [
            'images' => ['allowed' => true, 'max' => 1],
            'video'  => ['allowed' => false, 'max_seconds' => 0],
        ],
        'Influencer' => [
            'images' => ['allowed' => true, 'max' => 4],
            'video'  => ['allowed' => true, 'max_seconds' => 60],
        ],
        'default' => [
            'images' => ['allowed' => false, 'max' => 0],
            'video'  => ['allowed' => false, 'max_seconds' => 0],
        ],
    ],

    'image' => [
        'max_upload_kb' => 8192,
        'variants' => [
            'thumb'  => ['width' => 320,  'quality' => 70],
            'medium' => ['width' => 960,  'quality' => 75],
            'full'   => ['width' => 1600, 'quality' => 80],
        ],
        'format' => 'webp',
    ],

    'video' => [
        'max_upload_mb' => 100,
        'renditions' => [
            'sd' => ['height' => 480, 'video_kbps' => 500,  'audio_kbps' => 64],
            'hd' => ['height' => 720, 'video_kbps' => 1200, 'audio_kbps' => 96],
        ],
        'codec' => 'libvpx-vp9',
        'format' => 'webm',
        'poster_second' => 1,
    ],

];