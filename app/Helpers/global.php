<?php

use App\Models\Currency;
use App\Models\User;
use App\Models\UserComment;
use App\Models\UserLike;
use App\Models\UserView;
use App\Models\Wallet;

if (! function_exists('normalizeUserLevel')) {
    function normalizeUserLevel(?string $level): string
    {
        $level = trim((string) $level);

        return match (strtolower($level)) {
            'influencer' => 'Influencer',
            'creator' => 'Creator',
            'basic' => 'Basic',
            default => $level !== '' ? $level : 'Basic',
        };
    }
}

if (! function_exists('canUploadVideo')) {
    function canUploadVideo(?string $level): bool
    {
        return normalizeUserLevel($level) === 'Influencer';
    }
}

if (! function_exists('userLevel')) {
    function userLevel($userId = null)
    {
        $user = $userId ? User::find($userId) : auth()->user();

        return $user?->activeLevel?->plan_name ?? 'Basic';
    }
}

if (! function_exists('userBaseCurrency')) {
    function userBaseCurrency($userId = null): ?string
    {
        $userId ??= auth()->id();

        $currency = Wallet::where('user_id', $userId)->value('currency');

        return $currency ? strtoupper((string) $currency) : null;
    }
}

if (! function_exists('calculateUniqueEarningPerLike')) {
    function calculateUniqueEarningPerLike()
    {
        if (userLevel() == 'Basic' || userLevel() == 'Creator') {
            return 0.00002;
        } else {
            return 0.0004;
        }
    }
}

if (! function_exists('displayName')) {
    function displayName($name)
    {
        $bk = explode(' ', $name);

        return $bk[0];
    }
}

if (! function_exists('bankList')) {
    function bankList(): array
    {
        $url = rtrim((string) config('services.env.kora_base_url', 'https://api.korapay.com/merchant/api/v1'), '/')
            .'/misc/banks?countryCode=NG';

        $res = Illuminate\Support\Facades\Http::withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.config('services.env.kora_pub'),
        ])->get($url)->throw();

        return json_decode($res->getBody()->getContents(), true)['data'] ?? [];
    }
}

if (! function_exists('currencySymbol')) {
    function currencySymbol(?string $currency = null, ?string $userId = null): string
    {
        $currency = strtoupper($currency ?? userBaseCurrency($userId) ?? 'USD');

        $symbols = Currency::query()
            ->where('is_active', true)
            ->get(['code', 'symbol'])
            ->mapWithKeys(fn ($row) => [strtoupper((string) $row->code) => (string) $row->symbol])
            ->all();

        $fallbacks = [
            'USD' => '$',
            'NGN' => '₦',
            'EUR' => '€',
            'GBP' => '£',
        ];

        return $symbols[$currency] ?? $fallbacks[$currency] ?? '$';
    }
}

if (! function_exists('convertToBaseCurrency')) {
    function convertToBaseCurrency($amount, $currency): float
    {
        $rates = Currency::where('is_active', true)->pluck('base_rate', 'code')->toArray();
        $rate = $rates[strtoupper((string) $currency)] ?? 1;

        return (float) $amount * (float) $rate;
    }
}

if (! function_exists('estimatedEarnings')) {
    function estimatedEarnings($postId, ?string $currency = null): float
    {
        if (! $postId) {
            return 0.0;
        }

        $currency = strtoupper($currency ?? userBaseCurrency() ?? 'USD');
        $since = now()->subDays(30);

        $total = (float) UserView::where('post_id', $postId)->where('created_at', '>=', $since)->sum('amount')
            + (float) UserLike::where('post_id', $postId)->where('created_at', '>=', $since)->sum('amount')
            + (float) UserComment::where('post_id', $postId)->where('created_at', '>=', $since)->sum('amount');

        return (float) round(convertToBaseCurrency($total, $currency), 5);
    }
}


if (! function_exists('viewsAmountCalculator')) {
    function viewsAmountCalculator($postId, ?string $userId = null): float
    {
        if (! $postId) {
            return 0.0;
        }

        $currency = userBaseCurrency($userId) ?? 'USD';
        $viewsEarnings = (float) UserView::where('post_id', $postId)->sum('amount');

        return (float) round(convertToBaseCurrency($viewsEarnings, $currency), 5);
    }
}

if (! function_exists('likesAmountCalculator')) {
    function likesAmountCalculator($postId, ?string $userId = null): float
    {
        if (! $postId) {
            return 0.0;
        }

        $currency = userBaseCurrency($userId) ?? 'USD';
        $likesEarnings = (float) UserLike::where('post_id', $postId)->sum('amount');

        return (float) round(convertToBaseCurrency($likesEarnings, $currency), 5);
    }
}

if (! function_exists('commentsAmountCalculator')) {
    function commentsAmountCalculator($postId, ?string $userId = null): float
    {
        if (! $postId) {
            return 0.0;
        }

        $currency = userBaseCurrency($userId) ?? 'USD';
        $commentsEarnings = (float) UserComment::where('post_id', $postId)->sum('amount');

        return (float) round(convertToBaseCurrency($commentsEarnings, $currency), 5);
    }
}
