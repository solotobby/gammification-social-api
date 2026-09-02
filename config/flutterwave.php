<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Non-NGN level upgrades use Flutterwave (Korapay handles NGN).
    |--------------------------------------------------------------------------
    */

    'payment_channels' => [
        'card',
        'banktransfer',
        'ussd',
        'mobilemoneyghana',
        'mobilemoneytanzania',
        'mobilemoneyzambia',
        'mobilemoneyrwanda',
        'mobilemoneyuganda',
        'mpesa',
        'opay',
        'paypal',
        'googlepay',
        'applepay',
    ],

    'checkout' => [
        'session_duration_minutes' => 30,
        'max_retry_attempts' => 5,
        'plan_duration_months' => 24,
    ],

];
