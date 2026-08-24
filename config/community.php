<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform fee for paid communities
    |--------------------------------------------------------------------------
    |
    | The percentage Payhankey retains from every payment made into a paid
    | community. Stored as a whole-number integer (25 = 25%) — never a float —
    | to keep every downstream calculation free of floating-point rounding
    | drift. Change this via COMMUNITY_PLATFORM_FEE_PERCENT in .env rather
    | than editing this file directly, so it's environment-configurable
    | without a deploy.
    |
    | Note: existing communities keep the rate that was in effect when they
    | were created (see the `platform_fee_percent` snapshot column on the
    | communities table) — changing this value only affects communities
    | created after the change.
    |
    */

    'platform_fee_percent' => (int) env('COMMUNITY_PLATFORM_FEE_PERCENT', 10),

    /*
    |--------------------------------------------------------------------------
    | Billing intervals for subscription-type paid communities
    |--------------------------------------------------------------------------
    |
    | Only relevant when a paid community's billing_type is 'subscription'.
    | 'label' is used in dropdowns; 'suffix' is the short form appended to a
    | displayed price, e.g. "₦2,500/mo".
    |
    */

    'billing_intervals' => [
        'weekly' => ['label' => 'Weekly', 'suffix' => '/wk', 'adverb' => 'weekly'],
        'monthly' => ['label' => 'Monthly', 'suffix' => '/mo', 'adverb' => 'monthly'],
        'quarterly' => ['label' => 'Quarterly', 'suffix' => '/qtr', 'adverb' => 'quarterly'],
        'biannual' => ['label' => 'Bi-annual (every 6 months)', 'suffix' => '/6mo', 'adverb' => 'every 6 months'],
        'annual' => ['label' => 'Annual', 'suffix' => '/yr', 'adverb' => 'annually'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing types for paid communities
    |--------------------------------------------------------------------------
    |
    | 'one_off': a single one-time payment to join, no recurring interval.
    | 'subscription': recurring charge on one of the billing_intervals above.
    |
    */

    'billing_types' => [
        'one_off' => ['label' => 'One-off payment', 'suffix' => ' one-time'],
        'subscription' => ['label' => 'Subscription', 'suffix' => ''],
    ],

    /*
    |--------------------------------------------------------------------------
    | Minimum sticker price for paid communities (by currency)
    |--------------------------------------------------------------------------
    |
    | Optional per-currency overrides. Any active Payhankey currency without
    | an entry here gets a minimum derived from default_minimum_usd using
    | live exchange rates.
    |
    */

    'default_minimum_usd' => 5,

    'minimum_prices' => [
        'NGN' => 500,
        'USD' => 5,
    ],

];
