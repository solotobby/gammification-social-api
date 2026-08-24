<?php

namespace App\Support;

/**
 * Single source of truth for paid-community fee math shown in UI and stored
 * on subscriptions/payouts.
 *
 * Creator covers: members pay the list price; platform fee is deducted from
 * what the creator receives.
 *
 * Members cover: platform fee is {list × rate} added on top of the list price,
 * so the creator still receives the full list amount and members pay list + fee.
 */
final class CommunityFeeCalculator
{
    public static function breakdown(float $listPrice, int $platformFeePercent, string $feePayer): array
    {
        $listPrice = round($listPrice, 2);
        $rate = max(0, min(100, $platformFeePercent)) / 100;

        if ($feePayer === 'members') {
            $platformCut = round($listPrice * $rate, 2);
            $memberCharge = round($listPrice + $platformCut, 2);
            $creatorPayout = $listPrice;

            return [
                'memberCharge' => $memberCharge,
                'platformCut' => $platformCut,
                'creatorPayout' => $creatorPayout,
            ];
        }

        $memberCharge = $listPrice;
        $platformCut = round($memberCharge * $rate, 2);
        $creatorPayout = round($memberCharge - $platformCut, 2);

        return [
            'memberCharge' => $memberCharge,
            'platformCut' => $platformCut,
            'creatorPayout' => $creatorPayout,
        ];
    }
}
