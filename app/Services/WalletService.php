<?php

namespace App\Services;

use App\Models\Wallet;

class WalletService
{
    /**
     * @return array{
     *     currency: string,
     *     currency_symbol: string,
     *     balances: list<array{type: string, label: string, description: string|null, amount: float, formatted: string}>,
     *     total: array{amount: float, formatted: string}
     * }
     */
    public function balances(string $userId): array
    {
        $wallet = Wallet::query()->where('user_id', $userId)->first();

        $currency = strtoupper((string) ($wallet?->currency ?? userBaseCurrency($userId) ?? 'USD'));
        $symbol = currencySymbol($currency, $userId);

        $main = round((float) ($wallet?->balance ?? 0), 2);
        $referral = round((float) ($wallet?->referral_balance ?? 0), 2);
        $promoter = round((float) ($wallet?->promoter_balance ?? 0), 2);
        $total = round($main + $referral + $promoter, 2);

        $balances = [
            [
                'type' => 'main',
                'label' => 'Main balance',
                'description' => 'Content monetization',
                'amount' => $main,
                'formatted' => $this->formatMoney($main, $symbol),
            ],
            [
                'type' => 'referral',
                'label' => 'Referral balance',
                'description' => null,
                'amount' => $referral,
                'formatted' => $this->formatMoney($referral, $symbol),
            ],
            [
                'type' => 'promoter',
                'label' => 'Promotion balance',
                'description' => null,
                'amount' => $promoter,
                'formatted' => $this->formatMoney($promoter, $symbol),
            ],
        ];

        return [
            'currency' => $currency,
            'currency_symbol' => $symbol,
            'balances' => $balances,
            'total' => [
                'amount' => $total,
                'formatted' => $this->formatMoney($total, $symbol),
            ],
        ];
    }

    protected function formatMoney(float $amount, string $symbol): string
    {
        return $symbol.number_format($amount, 2);
    }
}
