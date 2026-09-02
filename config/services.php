<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'env' => [
        'kora_sec' => env('KORA_SEC'),
        'kora_pub' => env('KORA_PUB'),
        'kora_base_url' => env('KORA_BASE_URL', 'https://api.korapay.com/merchant/api/v1'),
        'flutterwave_secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
        'flutterwave_public_key' => env('FLUTTERWAVE_PUBLIC_KEY'),
        'flutterwave_base_url' => env('FLUTTERWAVE_BASE_URL', 'https://api.flutterwave.com/v3'),
        'flutterwave_webhook_hash' => env('FLUTTERWAVE_WEBHOOK_SECRET_HASH'),
    ],

    'payment' => [
        'korapay_redirect_url' => env('KORAPAY_REDIRECT_URL', env('APP_URL').'/payment/korapay/callback'),
        'flutterwave_redirect_url' => env('FLUTTERWAVE_REDIRECT_URL', env('APP_URL').'/payment/flutterwave/callback'),
    ],

];
