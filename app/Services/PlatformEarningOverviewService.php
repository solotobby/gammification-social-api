<?php

namespace App\Services;

use App\Models\UserComment;
use App\Models\UserLike;
use App\Models\UserView;
use Illuminate\Support\Facades\Cache;

class PlatformEarningOverviewService
{
    public const CACHE_TTL_SECONDS = 3600;

    public const DEFAULT_HOURS = 24;

    public function overview(string $userId, int $hours = self::DEFAULT_HOURS): array
    {
        $hours = max(1, min($hours, 168));
        $cacheKey = "earning_overview:{$userId}:{$hours}";

        return Cache::remember(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            fn () => $this->buildOverview($userId, $hours),
        );
    }

    protected function buildOverview(string $userId, int $hours): array
    {
        $since = now()->subHours($hours);
        $monthStart = now()->startOfMonth();
        $currency = strtoupper(userBaseCurrency($userId) ?? 'USD');
        $symbol = currencySymbol($currency, $userId);

        $peopleReached = (int) UserView::query()
            ->where('poster_user_id', $userId)
            ->where('created_at', '>=', $since)
            ->where('type', 'view')
            ->distinct()
            ->count('user_id');

        $monthlyRaw =
            (float) UserView::query()
                ->where('poster_user_id', $userId)
                ->where('created_at', '>=', $monthStart)
                ->sum('amount')
            + (float) UserLike::query()
                ->where('poster_user_id', $userId)
                ->where('created_at', '>=', $monthStart)
                ->sum('amount')
            + (float) UserComment::query()
                ->where('poster_user_id', $userId)
                ->where('created_at', '>=', $monthStart)
                ->sum('amount');

        $monthlyAmount = round(convertToBaseCurrency($monthlyRaw, $currency), 2);
        $formattedAmount = $this->formatMoney($monthlyAmount, $symbol);
        $hoursLabel = $hours === 1 ? '1 hour' : "{$hours} hours";

        $reachMessage = $peopleReached > 0
            ? sprintf(
                'Your post has reached %s %s in the last %s.',
                number_format($peopleReached),
                $peopleReached === 1 ? 'person' : 'people',
                $hoursLabel,
            )
            : sprintf(
                "Your posts haven't reached anyone in the last %s yet.",
                $hoursLabel,
            );

        $earningsMessage = $monthlyAmount > 0
            ? "{$formattedAmount} so far this month..keep it up"
            : 'Keep posting — your earnings this month start with your next engagement.';

        $generatedAt = now();

        return [
            'generated_at' => $generatedAt->toIso8601String(),
            'refresh_after' => $generatedAt->copy()->addHour()->toIso8601String(),
            'period_hours' => $hours,
            'reach' => [
                'people' => $peopleReached,
                'hours' => $hours,
                'message' => $reachMessage,
            ],
            'monthly_earnings' => [
                'amount' => $monthlyAmount,
                'currency' => $currency,
                'currency_symbol' => $symbol,
                'formatted' => $formattedAmount,
                'message' => $earningsMessage,
            ],
            'messages' => [
                $reachMessage,
                $earningsMessage,
            ],
        ];
    }

    protected function formatMoney(float $amount, string $symbol): string
    {
        $decimals = fmod($amount, 1.0) === 0.0 ? 0 : 2;

        return $symbol.number_format($amount, $decimals);
    }
}
