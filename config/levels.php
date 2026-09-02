<?php

return [

    'order' => ['Basic', 'Creator', 'Influencer'],

    'subscription_discount' => 0.10,

    'plans' => [
        'Basic' => [
            'badge' => null,
            'tagline' => 'Your starting point for creating and growing on Payhankey.',
            'features' => [
                'Unlimited posts & quizzes',
                'Payhankey Rolls (Videos)',
                'Full dashboard access',
                'Discover and join communities',
            ],
        ],
        'Creator' => [
            'badge' => 'Most popular',
            'tagline' => 'For creators ready to monetize their content and grow their audience.',
            'features' => [
                'Everything in Basic',
                'Content monetization',
                'Create & monetize communities',
                'Verified creator badge',
                'Image posting',
                'Priority discovery',
                'AI Creator support tools',
            ],
        ],
        'Influencer' => [
            'badge' => null,
            'tagline' => 'For established creators ready to increase their reach and earning potential.',
            'features' => [
                'Everything in Creator',
                'Payhankey Rolls (Videos)',
                'Influencer verification badge',
                'Influencer profile ring',
                'Higher content limits',
                'Top-feed placement',
                'Priority discovery',
                'Advanced creator opportunities',
            ],
        ],
    ],

];
