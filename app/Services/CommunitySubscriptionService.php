<?php

namespace App\Services;

use App\Models\Community;
use App\Models\CommunitySubscription;
use App\Models\Transaction;
use App\Models\User;
use App\Support\CommunityFeeCalculator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class CommunitySubscriptionService
{
    public function __construct(
        protected TransactionService $transactionService,
        protected CommunityMembershipService $membershipService,
        protected CommunityPayoutService $payoutService,
    ) {}

    /**
     * Generate checkout link for paid community subscription or one-off payment.
     *
     * @return array{checkout_url: string, reference: string, transaction_id: string, amount: float, currency: string, provider: string}
     */
    public function generatePaymentLink(Community $community, User $user, ?string $idempotencyKey = null): array
    {
        if ($community->type !== 'paid') {
            throw new InvalidArgumentException('This community does not require payment.');
        }

        $userCurrency = userBaseCurrency($user->id) ?? 'NGN';

        if (! $community->isInCurrency($userCurrency)) {
            throw new InvalidArgumentException('This community is not available in your currency.');
        }

        $idempotencyKey ??= (string) Str::uuid();

        // Check for existing pending transaction with checkout URL
        $existing = Transaction::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('user_id', $user->id)
            ->first();

        if ($existing?->meta['checkout_url'] ?? null) {
            return [
                'checkout_url' => $existing->meta['checkout_url'],
                'reference' => $existing->ref,
                'transaction_id' => $existing->id,
                'amount' => (float) $existing->amount,
                'currency' => $existing->currency,
                'provider' => $existing->provider,
            ];
        }

        $chargeAmount = (float) ($community->member_charge ?? $community->monthly_fee);
        $convertedAmount = (float) convertCurrency($chargeAmount, $community->currency ?? $userCurrency, $userCurrency);

        if ($userCurrency === 'NGN') {
            return $this->initiateKorapay($community, $user, $convertedAmount, $userCurrency, $idempotencyKey);
        }

        return $this->initiateFlutterwave($community, $user, $convertedAmount, $userCurrency, $idempotencyKey);
    }

    private function initiateKorapay(
        Community $community,
        User $user,
        float $amount,
        string $currency,
        string $idempotencyKey,
    ): array {
        $secret = config('services.env.kora_sec');
        if (! $secret) {
            throw new RuntimeException('Korapay is not configured.');
        }

        $reference = generateTransactionRef('community');

        $transaction = $this->transactionService->createTransaction(
            user: $user,
            idempotencyKey: $idempotencyKey,
            provider: 'korapay',
            reference: $reference,
            amount: $amount,
            currency: $currency,
            status: 'initiated',
            action: 'Debit',
            type: 'community_'.$community->billing_type,
            description: 'Payment for community ('.$community->billing_type.'): '.$community->name,
            meta: [
                'community_id' => (string) $community->id,
                'community_name' => $community->name,
                'user_id' => (string) $user->id,
                'amount' => $amount,
                'currency' => $currency,
                'billing_type' => $community->billing_type,
                'billing_interval' => $community->billing_interval,
            ],
            customer: [
                'name' => $user->name,
                'email' => $user->email,
            ],
        );

        $payload = [
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'reference' => $reference,
            'narration' => 'Payment for community ('.$community->billing_type.'): '.$community->name,
            'channels' => ['card', 'bank_transfer', 'pay_with_bank'],
            'notification_url' => url('/v1/webhooks/flutterwave'), // handled in webhooks
            'customer' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
            'metadata' => [
                'community_id' => (string) $community->id,
                'user_id' => (string) $user->id,
                'billing_type' => $community->billing_type,
            ],
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$secret,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->post('https://api.korapay.com/merchant/api/v1/charges/initialize', $payload);

            if (! $response->successful()) {
                Log::error('Korapay community checkout initialization failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'reference' => $reference,
                ]);

                $this->transactionService->markFailed($transaction, ['error' => $response->body()]);
                throw new RuntimeException('Unable to initialize Korapay payment.');
            }

            $checkoutUrl = (string) $response->json('data.checkout_url');

            $transaction->update([
                'meta' => array_merge($transaction->meta ?? [], ['checkout_url' => $checkoutUrl]),
            ]);

            return [
                'checkout_url' => $checkoutUrl,
                'reference' => $reference,
                'transaction_id' => $transaction->id,
                'amount' => $amount,
                'currency' => $currency,
                'provider' => 'korapay',
            ];
        } catch (\Throwable $e) {
            $this->transactionService->markFailed($transaction, ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function initiateFlutterwave(
        Community $community,
        User $user,
        float $amount,
        string $currency,
        string $idempotencyKey,
    ): array {
        $secret = config('services.env.flutterwave_secret_key');
        if (! $secret) {
            throw new RuntimeException('Flutterwave is not configured.');
        }

        $reference = generateTransactionRef('community');

        $transaction = $this->transactionService->createTransaction(
            user: $user,
            idempotencyKey: $idempotencyKey,
            provider: 'flutterwave',
            reference: $reference,
            amount: $amount,
            currency: $currency,
            status: 'initiated',
            action: 'Debit',
            type: 'community_'.$community->billing_type,
            description: 'Payment for community ('.$community->billing_type.'): '.$community->name,
            meta: [
                'community_id' => (string) $community->id,
                'community_name' => $community->name,
                'user_id' => (string) $user->id,
                'amount' => $amount,
                'currency' => $currency,
                'billing_type' => $community->billing_type,
                'billing_interval' => $community->billing_interval,
            ],
            customer: [
                'name' => $user->name,
                'email' => $user->email,
            ],
        );

        $payload = [
            'tx_ref' => $reference,
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'customer' => [
                'email' => $user->email,
                'name' => $user->name,
            ],
            'customizations' => [
                'title' => $community->name,
                'description' => 'Payment for community membership',
            ],
            'meta' => [
                'community_id' => (string) $community->id,
                'user_id' => (string) $user->id,
                'billing_type' => $community->billing_type,
                'billing_interval' => $community->billing_interval,
                'type' => 'community_'.$community->billing_type,
            ],
        ];

        try {
            $response = Http::withToken($secret)
                ->acceptJson()
                ->post('https://api.flutterwave.com/v3/payments', $payload);

            if (! $response->successful()) {
                Log::error('Flutterwave community checkout initialization failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'reference' => $reference,
                ]);

                $this->transactionService->markFailed($transaction, ['error' => $response->body()]);
                throw new RuntimeException('Unable to initialize Flutterwave payment.');
            }

            $checkoutUrl = (string) $response->json('data.link');

            $transaction->update([
                'meta' => array_merge($transaction->meta ?? [], ['checkout_url' => $checkoutUrl]),
            ]);

            return [
                'checkout_url' => $checkoutUrl,
                'reference' => $reference,
                'transaction_id' => $transaction->id,
                'amount' => $amount,
                'currency' => $currency,
                'provider' => 'flutterwave',
            ];
        } catch (\Throwable $e) {
            $this->transactionService->markFailed($transaction, ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Process a verified successful payment from webhook or verification callback.
     */
    public function processSuccessfulPayment(
        Community $community,
        User $user,
        Transaction $transaction,
        ?string $gatewayRef = null,
        array $gatewayMeta = [],
    ): CommunitySubscription {
        $active = CommunitySubscription::query()
            ->where('community_id', $community->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->latest()
            ->first();

        if ($active && $active->isRecurring() && $community->billing_type === 'subscription') {
            return $this->renew($active, $transaction, $gatewayRef, $gatewayMeta);
        }

        if ($active && $active->isOneOff()) {
            $this->transactionService->markSuccessful($transaction, $transaction->meta ?? []);

            return $active;
        }

        return $this->initiateAndActivate($community, $user, $transaction, $gatewayRef, $gatewayMeta);
    }

    private function initiateAndActivate(
        Community $community,
        User $user,
        Transaction $transaction,
        ?string $gatewayRef = null,
        array $gatewayMeta = [],
    ): CommunitySubscription {
        $basePrice = (float) $community->monthly_fee;
        $breakdown = CommunityFeeCalculator::breakdown(
            $basePrice,
            (int) ($community->platform_fee_percent ?: 0),
            (string) ($community->fee_payer ?: 'creator'),
        );

        $subscription = CommunitySubscription::create([
            'id' => (string) Str::uuid(),
            'community_id' => $community->id,
            'user_id' => $user->id,
            'billing_type' => $community->billing_type ?: 'one_off',
            'billing_interval' => $community->billing_type === 'subscription'
                ? $community->billing_interval
                : null,
            'fee_payer' => $community->fee_payer ?: 'creator',
            'amount' => $breakdown['memberCharge'],
            'platform_fee' => $breakdown['platformCut'],
            'creator_amount' => $breakdown['creatorPayout'],
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => $this->calculateExpiry($community->billing_type, $community->billing_interval),
            'cancelled_at' => null,
            'gateway' => $transaction->provider,
            'gateway_reference' => $gatewayRef ?: $transaction->ref,
            'gateway_meta' => $gatewayMeta,
        ]);

        $this->membershipService->attachMember($community, $user->id);

        $this->transactionService->markSuccessful($transaction, [
            'community_id' => $community->id,
            'user_id' => $user->id,
            'amount' => $subscription->amount,
            'currency' => $transaction->currency,
            'billing_type' => $subscription->billing_type,
            'billing_interval' => $subscription->billing_interval,
        ]);

        $this->payoutService->recordFromSubscription($subscription, $transaction);

        return $subscription;
    }

    private function renew(
        CommunitySubscription $subscription,
        Transaction $transaction,
        ?string $gatewayRef = null,
        array $gatewayMeta = [],
    ): CommunitySubscription {
        $subscription->update([
            'expires_at' => $this->calculateExpiry($subscription->billing_type, $subscription->billing_interval),
            'cancelled_at' => null,
            'gateway_reference' => $gatewayRef ?: $transaction->ref,
            'gateway_meta' => $gatewayMeta ?: $subscription->gateway_meta,
        ]);

        $this->membershipService->attachMember($subscription->community, $subscription->user_id);

        $this->transactionService->markSuccessful($transaction, [
            'community_id' => $subscription->community_id,
            'user_id' => $subscription->user_id,
            'amount' => $subscription->amount,
            'currency' => $transaction->currency,
            'billing_type' => $subscription->billing_type,
            'billing_interval' => $subscription->billing_interval,
            'renewal' => true,
        ]);

        $this->payoutService->recordFromSubscription($subscription, $transaction);

        return $subscription;
    }

    public function subscriptionStatus(Community $community, User $user): array
    {
        $sub = CommunitySubscription::query()
            ->where('community_id', $community->id)
            ->where('user_id', $user->id)
            ->latest()
            ->first();

        $isActive = $sub ? ($sub->status === 'active' && ($sub->expires_at === null || $sub->expires_at->isFuture())) : false;

        return [
            'has_subscription' => $sub !== null,
            'is_active' => $isActive,
            'status' => $sub?->status,
            'billing_type' => $sub?->billing_type ?? $community->billing_type,
            'billing_interval' => $sub?->billing_interval ?? $community->billing_interval,
            'amount' => $sub ? (float) $sub->amount : (float) $community->monthly_fee,
            'starts_at' => $sub?->starts_at?->toIso8601String(),
            'expires_at' => $sub?->expires_at?->toIso8601String(),
        ];
    }

    /**
     * Creator earnings dashboard for paid community owners.
     */
    public function payoutDashboard(User $owner, Community $community, string $period = 'all', int $perPage = 15): array
    {
        if ((string) $community->user_id !== (string) $owner->id) {
            throw new InvalidArgumentException('Only the community owner can view earnings.');
        }

        $base = CommunitySubscription::query()
            ->where('community_id', $community->id)
            ->whereIn('status', ['active', 'expired', 'cancelled']);

        $filtered = match ($period) {
            '7d' => (clone $base)->where('starts_at', '>=', now()->subDays(7)),
            '30d' => (clone $base)->where('starts_at', '>=', now()->subDays(30)),
            '90d' => (clone $base)->where('starts_at', '>=', now()->subDays(90)),
            default => (clone $base),
        };

        $stats = [
            'period' => $period,
            'gross' => (float) (clone $filtered)->sum('amount'),
            'platform_fee' => (float) (clone $filtered)->sum('platform_fee'),
            'creator_amount' => (float) (clone $filtered)->sum('creator_amount'),
            'count' => (int) (clone $filtered)->count(),
            'active_subscribers_count' => CommunitySubscription::query()
                ->where('community_id', $community->id)
                ->where('status', 'active')
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->count(),
            'platform_fee_percent' => (int) ($community->platform_fee_percent ?? config('community.platform_fee_percent', 10)),
            'currency' => $community->currency ?? userBaseCurrency($owner->id) ?? 'NGN',
        ];

        $payments = $filtered->with('user:id,username,name,avatar')
            ->latest('starts_at')
            ->paginate($perPage);

        return [
            'stats' => $stats,
            'payments' => $payments,
        ];
    }

    private function calculateExpiry(?string $billingType, ?string $billingInterval): ?Carbon
    {
        if ($billingType === 'one_off') {
            return null;
        }

        return match ($billingInterval) {
            'weekly' => now()->addWeek(),
            'monthly' => now()->addMonth(),
            'quarterly' => now()->addMonths(3),
            'biannual' => now()->addMonths(6),
            'yearly', 'annual' => now()->addYear(),
            default => now()->addMonth(),
        };
    }
}
