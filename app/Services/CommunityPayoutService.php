<?php

namespace App\Services;

use App\Models\CommunityPayout;
use App\Models\CommunitySubscription;
use App\Models\Transaction;

class CommunityPayoutService
{
    /**
     * Record a completed payout row when a community subscription payment
     * is confirmed. Idempotent per subscription — won't double-record.
     */
    public function recordFromSubscription(
        CommunitySubscription $subscription,
        ?Transaction $transaction = null,
    ): CommunityPayout {
        $existing = CommunityPayout::where('community_subscription_id', $subscription->id)->first();

        if ($existing) {
            return $existing;
        }

        $currency = $transaction?->currency
            ?? $subscription->community->currency
            ?? userBaseCurrency($subscription->user_id)
            ?? 'NGN';

        return CommunityPayout::create([
            'community_id' => $subscription->community_id,
            'community_subscription_id' => $subscription->id,
            'transaction_id' => $transaction?->id,
            'payer_user_id' => $subscription->user_id,
            'gross_amount' => $subscription->amount,
            'platform_fee' => $subscription->platform_fee,
            'creator_amount' => $subscription->creator_amount,
            'currency' => strtoupper((string) $currency),
            'billing_type' => $subscription->billing_type,
            'billing_interval' => $subscription->billing_interval,
            'status' => 'completed',
            'paid_at' => now(),
        ]);
    }
}
