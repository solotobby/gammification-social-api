<?php

namespace App\Services;

use App\Models\Level;
use App\Models\User;
use App\Models\UserLevel;
use Illuminate\Support\Collection;

class LevelUpgradeService
{
    /**
     * @return array{
     *     current_level: string,
     *     currency: string|null,
     *     currency_symbol: string,
     *     subscription: array<string, mixed>|null,
     *     billing: array<string, mixed>,
     *     levels: array<int, array<string, mixed>>
     * }
     */
    public function listForUser(User $user): array
    {
        $currency = userBaseCurrency($user->id);
        $currentLevel = normalizeUserLevel(userLevel($user->id) ?? 'Basic');
        $currentRank = $this->rank($currentLevel);
        $symbol = currencySymbol($currency, $user->id);
        $discount = (float) config('levels.subscription_discount', 0.10);
        $isNgn = strtoupper((string) $currency) === 'NGN';

        $subscription = UserLevel::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        $levels = $this->orderedLevels()->map(function (Level $level) use (
            $user,
            $currency,
            $symbol,
            $currentLevel,
            $currentRank,
            $discount,
            $isNgn,
        ) {
            $name = normalizeUserLevel($level->name);
            $rank = $this->rank($name);
            $isCurrent = $name === $currentLevel;
            $isFree = (float) $level->amount <= 0;
            $listPrice = $currency ? convertToBaseCurrency((float) $level->amount, $currency) : (float) $level->amount;
            $subscriptionPrice = round($listPrice * (1 - $discount), 2);
            $regBonus = $currency ? convertToBaseCurrency((float) $level->reg_bonus, $currency) : (float) $level->reg_bonus;
            $refBonus = $currency ? convertToBaseCurrency((float) $level->ref_bonus, $currency) : (float) $level->ref_bonus;
            $minWithdrawal = $currency ? convertToBaseCurrency((float) $level->min_withdrawal, $currency) : (float) $level->min_withdrawal;
            $copy = config("levels.plans.{$name}", config('levels.plans.Basic'));
            $mediaTier = config("media_tiers.tiers.{$name}", config('media_tiers.tiers.default'));
            $provider = $isNgn ? 'korapay' : 'flutterwave';
            $canCheckout = ! $isFree && $currency && $rank > $currentRank;

            return [
                'id' => $level->id,
                'name' => $name,
                'rank' => $rank,
                'badge' => $copy['badge'] ?? null,
                'tagline' => $copy['tagline'] ?? null,
                'features' => $copy['features'] ?? [],
                'is_current' => $isCurrent,
                'is_free' => $isFree,
                'is_upgrade' => ! $isFree && $rank > $currentRank,
                'is_downgrade' => $rank < $currentRank,
                'is_selectable' => ! ($currentRank > $this->rank('Basic') && $name === 'Basic'),
                'pricing' => [
                    'list_price' => round($listPrice, 2),
                    'subscription_price' => $isFree ? 0 : round($subscriptionPrice, 2),
                    'currency' => $currency,
                    'currency_symbol' => $symbol,
                    'interval' => 'monthly',
                    'reg_bonus' => round($regBonus, 2),
                    'ref_bonus' => round($refBonus, 2),
                    'min_withdrawal' => round($minWithdrawal, 2),
                    'subscription_discount_percent' => $isNgn && ! $isFree ? (int) round($discount * 100) : 0,
                ],
                'earnings' => [
                    'per_view' => (float) $level->earning_per_view,
                    'per_like' => (float) ($level->earning_per_like ?? 0),
                    'per_comment' => (float) ($level->earning_per_comment ?? 0),
                ],
                'media' => [
                    'images' => $mediaTier['images'] ?? ['allowed' => false, 'max' => 0],
                    'video' => $mediaTier['video'] ?? ['allowed' => false, 'max_seconds' => 0],
                ],
                'payment' => [
                    'provider' => $canCheckout ? $provider : null,
                    'available' => $canCheckout,
                    'checkout_method' => 'POST',
                    'checkout_path' => $canCheckout ? "/v1/user/levels/{$level->id}/checkout" : null,
                    'public_key' => $canCheckout && $provider === 'flutterwave'
                        ? config('services.env.flutterwave_public_key')
                        : null,
                ],
            ];
        })->values()->all();

        return [
            'current_level' => $currentLevel,
            'currency' => $currency,
            'currency_symbol' => $symbol,
            'subscription' => $subscription ? [
                'plan_name' => normalizeUserLevel($subscription->plan_name ?? $currentLevel),
                'status' => $subscription->status,
                'start_date' => $subscription->start_date?->toIso8601String(),
                'next_payment_date' => $subscription->next_payment_date?->toIso8601String(),
            ] : null,
            'billing' => [
                'supports_subscription_discount' => $isNgn,
                'modes' => $isNgn ? ['subscription', 'payg'] : ['subscription'],
                'default_mode' => 'subscription',
                'subscription_discount_percent' => $isNgn ? (int) round($discount * 100) : 0,
            ],
            'levels' => $levels,
            'upgrade_options' => collect($levels)
                ->filter(fn (array $level) => $level['is_upgrade'])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return Collection<int, Level>
     */
    private function orderedLevels(): Collection
    {
        $order = config('levels.order', ['Basic', 'Creator', 'Influencer']);

        return Level::query()
            ->get()
            ->sortBy(function (Level $level) use ($order) {
                $index = array_search(normalizeUserLevel($level->name), $order, true);

                return $index === false ? 99 : $index;
            })
            ->values();
    }

    private function rank(string $level): int
    {
        $order = config('levels.order', ['Basic', 'Creator', 'Influencer']);
        $index = array_search(normalizeUserLevel($level), $order, true);

        return $index === false ? 0 : (int) $index;
    }
}
