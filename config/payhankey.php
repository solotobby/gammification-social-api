<?php

return [
    'support_email' => env('PAYHANKEY_SUPPORT_EMAIL', 'support@payhankey.com'),

    'child_safety_email' => env('PAYHANKEY_CHILD_SAFETY_EMAIL', 'safety@payhankey.com'),

    'child_safety_contact' => env('PAYHANKEY_CHILD_SAFETY_CONTACT', 'Payhankey Trust & Safety Team'),

    'social' => [
        'tiktok' => 'https://www.tiktok.com/@payhankeyofficial',
        'instagram' => 'https://www.instagram.com/payhankey_official',
        'facebook' => 'https://www.facebook.com/profile.php?id=61561454191408',
        'x' => 'https://x.com/Payhankey',
    ],

    'stats' => [
        'creators' => '32K+',
        'paid_creators' => '1.7K+',
        'countries' => '40+',
        'paid_out_usd' => '$486K+',
    ],

    'paykoin' => [
        'min_top_up' => 100,
        'rates' => [
            'NGN' => [
                'list' => 10,
                'convert' => 7.5,
            ],
            'USD' => [
                'list' => 0.10,
                'convert' => 0.075,
            ],
        ],
        'gift_artifacts' => [
            ['id' => 'rose', 'name' => 'Rose', 'emoji' => '🌹', 'price' => 5, 'tier' => 'classic'],
            ['id' => 'heart', 'name' => 'Heart', 'emoji' => '❤️', 'price' => 10, 'tier' => 'classic'],
            ['id' => 'balloon', 'name' => 'Balloon', 'emoji' => '🎈', 'price' => 15, 'tier' => 'classic'],
            ['id' => 'star', 'name' => 'Star', 'emoji' => '⭐', 'price' => 25, 'tier' => 'classic'],
            ['id' => 'trophy', 'name' => 'Trophy', 'emoji' => '🏆', 'price' => 50, 'tier' => 'classic'],
            ['id' => 'pkcoin', 'name' => 'PK Coin', 'emoji' => '🪙', 'price' => 45, 'tier' => 'payhankey'],
            ['id' => 'clutch', 'name' => 'Clutch', 'emoji' => '👜', 'price' => 60, 'tier' => 'fashion'],
            ['id' => 'pkheart', 'name' => 'Purple Heart', 'emoji' => '💜', 'price' => 65, 'tier' => 'payhankey'],
            ['id' => 'chain', 'name' => 'Gold Chain', 'emoji' => '📿', 'price' => 75, 'tier' => 'fashion'],
            ['id' => 'pkbadge', 'name' => 'Payhankey Badge', 'emoji' => '🎖️', 'price' => 90, 'tier' => 'payhankey'],
            ['id' => 'ring', 'name' => 'Diamond Ring', 'emoji' => '💍', 'price' => 100, 'tier' => 'fashion'],
            ['id' => 'gem', 'name' => 'Gem', 'emoji' => '💎', 'price' => 125, 'tier' => 'fashion'],
            ['id' => 'pkshield', 'name' => 'Creator Shield', 'emoji' => '🛡️', 'price' => 150, 'tier' => 'payhankey'],
            ['id' => 'crown', 'name' => 'Crown', 'emoji' => '👑', 'price' => 175, 'tier' => 'fashion'],
            ['id' => 'rocket', 'name' => 'Rocket', 'emoji' => '🚀', 'price' => 200, 'tier' => 'premium'],
            ['id' => 'pkbolt', 'name' => 'PK Bolt', 'emoji' => '⚡', 'price' => 250, 'tier' => 'payhankey'],
            ['id' => 'castle', 'name' => 'Castle', 'emoji' => '🏰', 'price' => 350, 'tier' => 'premium'],
            ['id' => 'yacht', 'name' => 'Yacht', 'emoji' => '🛥️', 'price' => 500, 'tier' => 'premium'],
            ['id' => 'dragon', 'name' => 'Dragon', 'emoji' => '🐉', 'price' => 750, 'tier' => 'premium'],
            ['id' => 'galaxy', 'name' => 'Galaxy', 'emoji' => '🌌', 'price' => 1000, 'tier' => 'premium'],
        ],
    ],
];
