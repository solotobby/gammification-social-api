<?php

namespace App\Services;

use App\Models\Level;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use InvalidArgumentException;

class LevelUpgradeCheckoutService
{
    public function __construct(
        protected LevelUpgradeService $levelUpgradeService,
        protected KorapayUpgradeService $korapayUpgradeService,
        protected FlutterwaveUpgradeService $flutterwaveUpgradeService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function checkout(User $user, string $levelId, string $billingMode = 'subscription', ?string $idempotencyKey = null): array
    {
        $currency = userBaseCurrency($user->id);

        if (! $currency) {
            throw new InvalidArgumentException('Set up your wallet currency before upgrading.');
        }

        $level = Level::query()->find($levelId);

        if (! $level) {
            throw new ModelNotFoundException('Level not found.');
        }

        $this->assertUpgradeAllowed($user, $level);

        $currency = strtoupper($currency);
        $billingMode = in_array($billingMode, ['subscription', 'payg'], true) ? $billingMode : 'subscription';
        $idempotencyKey = $idempotencyKey ?: (string) Str::uuid();

        if ($currency === 'NGN') {
            $amount = $this->ngnChargeAmount($level, $billingMode);

            $result = $this->korapayUpgradeService->initiateCheckout(
                $user,
                $level,
                $amount,
                $billingMode,
                $idempotencyKey,
            );

            return $this->formatResponse($level, 'korapay', $billingMode, $result);
        }

        if ($billingMode === 'payg') {
            throw new InvalidArgumentException('Pay-as-you-go billing is only available for NGN wallets.');
        }

        $result = $this->flutterwaveUpgradeService->initiateCheckout(
            $user,
            $level,
            $currency,
            $idempotencyKey,
        );

        return $this->formatResponse($level, 'flutterwave', 'subscription', $result);
    }

    private function assertUpgradeAllowed(User $user, Level $level): void
    {
        $catalog = $this->levelUpgradeService->listForUser($user);
        $target = collect($catalog['levels'])->firstWhere('id', $level->id);

        if (! $target) {
            throw new InvalidArgumentException('Level is not available.');
        }

        if ($target['is_current']) {
            throw new InvalidArgumentException('You are already on this plan.');
        }

        if (! $target['is_upgrade']) {
            throw new InvalidArgumentException('You can only upgrade to a higher plan.');
        }
    }

    private function ngnChargeAmount(Level $level, string $billingMode): float
    {
        $listPrice = convertToBaseCurrency((float) $level->amount, 'NGN');

        if ($billingMode === 'subscription') {
            $discount = (float) config('levels.subscription_discount', 0.10);

            return round($listPrice * (1 - $discount), 2);
        }

        return round($listPrice, 2);
    }

    /**
     * @param  array{checkout_url: string, reference: string, transaction_id: string, amount: float, currency: string}  $result
     * @return array<string, mixed>
     */
    private function formatResponse(Level $level, string $provider, string $billingMode, array $result): array
    {
        $response = [
            'provider' => $provider,
            'billing_mode' => $billingMode,
            'checkout_url' => $result['checkout_url'],
            'reference' => $result['reference'],
            'transaction_id' => $result['transaction_id'],
            'amount' => $result['amount'],
            'currency' => $result['currency'],
            'level' => [
                'id' => $level->id,
                'name' => normalizeUserLevel($level->name),
            ],
        ];

        if ($provider === 'flutterwave' && ! empty($result['public_key'])) {
            $response['public_key'] = $result['public_key'];
        }

        return $response;
    }
}
