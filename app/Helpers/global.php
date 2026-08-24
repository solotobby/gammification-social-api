<?php

use App\Models\User;
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
