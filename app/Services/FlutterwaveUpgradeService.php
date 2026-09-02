<?php

namespace App\Services;

use App\Models\Level;
use App\Models\LevelPlanId;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserPaymentPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class FlutterwaveUpgradeService
{
    public function __construct(protected TransactionService $transactionService) {}

    /**
     * @return array{checkout_url: string, reference: string, transaction_id: string, amount: float, currency: string, public_key: string|null}
     */
    public function initiateCheckout(
        User $user,
        Level $level,
        string $currency,
        ?string $idempotencyKey = null,
    ): array {
        $currency = strtoupper($currency);

        if ($currency === 'NGN') {
            throw new InvalidArgumentException('NGN upgrades use Korapay.');
        }

        $amount = convertToBaseCurrency((float) $level->amount, $currency);

        if ($amount <= 0) {
            throw new InvalidArgumentException('This level does not require payment.');
        }

        $secret = config('services.env.flutterwave_secret_key');
        $publicKey = config('services.env.flutterwave_public_key');
        $baseUrl = rtrim((string) config('services.env.flutterwave_base_url'), '/');
        $redirectUrl = config('services.payment.flutterwave_redirect_url');

        if (! $secret || ! $publicKey) {
            throw new RuntimeException('Flutterwave is not configured. Set FLUTTERWAVE_SECRET_KEY and FLUTTERWAVE_PUBLIC_KEY.');
        }

        if (! $redirectUrl) {
            throw new RuntimeException('Flutterwave redirect URL is not configured. Set FLUTTERWAVE_REDIRECT_URL.');
        }

        return DB::transaction(function () use ($user, $level, $currency, $amount, $idempotencyKey, $secret, $publicKey, $baseUrl, $redirectUrl) {
            $idempotencyKey ??= (string) Str::uuid();

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
                    'public_key' => $publicKey,
                ];
            }

            $paymentPlanId = $this->resolvePaymentPlanId($user, $level, $currency, $secret, $baseUrl, $amount);
            $reference = generateTransactionRef();

            $transaction = $this->transactionService->createTransaction(
                user: $user,
                idempotencyKey: $idempotencyKey,
                provider: 'flutterwave',
                reference: $reference,
                amount: $amount,
                currency: $currency,
                status: 'initiated',
                action: 'Debit',
                type: 'subscription_upgrade',
                description: "{$user->name} upgrade to {$level->name}",
                meta: [
                    'level_id' => $level->id,
                    'level_name' => $level->name,
                    'billing_mode' => 'subscription',
                    'payment_plan_id' => $paymentPlanId,
                ],
            );

            $payload = [
                'tx_ref' => $reference,
                'amount' => $amount,
                'currency' => $currency,
                'redirect_url' => $redirectUrl,
                'customer' => [
                    'email' => $user->email,
                    'phone_number' => $user->phone ?? null,
                    'name' => $user->name,
                ],
                'customizations' => [
                    'title' => "Upgrade to {$level->name}",
                ],
                'configuration' => [
                    'session_duration' => (int) config('flutterwave.checkout.session_duration_minutes', 30),
                ],
                'payment_options' => implode(',', config('flutterwave.payment_channels', ['card'])),
                'max_retry_attempt' => (int) config('flutterwave.checkout.max_retry_attempts', 5),
                'link_expiration' => now()
                    ->addMinutes((int) config('flutterwave.checkout.session_duration_minutes', 30))
                    ->toIso8601String(),
                'meta' => [
                    'transaction_id' => $transaction->id,
                    'level_id' => $level->id,
                    'user_id' => $user->id,
                    'type' => 'subscription_upgrade',
                ],
            ];

            if ($paymentPlanId) {
                $payload['payment_plan'] = $paymentPlanId;
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$secret,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Idempotency-Key' => $idempotencyKey,
            ])
                ->timeout(30)
                ->connectTimeout(10)
                ->retry(3, 1000)
                ->post("{$baseUrl}/payments", $payload);

            if (! $response->successful()) {
                $transaction->update([
                    'status' => 'failed',
                    'meta' => array_merge($transaction->meta ?? [], [
                        'gateway_response' => $response->json(),
                    ]),
                ]);

                Log::error('Flutterwave upgrade init failed', [
                    'reference' => $reference,
                    'currency' => $currency,
                    'response' => $response->json(),
                ]);

                throw new RuntimeException(
                    data_get($response->json(), 'message', 'Unable to initialize Flutterwave checkout.')
                );
            }

            $checkoutUrl = data_get($response->json(), 'data.link');

            if (! $checkoutUrl) {
                $transaction->update(['status' => 'failed']);
                throw new RuntimeException('Flutterwave checkout URL missing.');
            }

            $transaction->update([
                'meta' => array_merge($transaction->meta ?? [], [
                    'checkout_url' => $checkoutUrl,
                    'gateway_response' => $response->json(),
                ]),
            ]);

            return [
                'checkout_url' => $checkoutUrl,
                'reference' => $reference,
                'transaction_id' => $transaction->id,
                'amount' => $amount,
                'currency' => $currency,
                'public_key' => $publicKey,
            ];
        });
    }

    private function resolvePaymentPlanId(
        User $user,
        Level $level,
        string $currency,
        string $secret,
        string $baseUrl,
        float $amount,
    ): ?string {
        $adminPlan = LevelPlanId::query()
            ->where('level_id', $level->id)
            ->where('currency', $currency)
            ->where('provider', 'flutterwave')
            ->where('status', 'active')
            ->value('plan_code');

        if ($adminPlan) {
            return (string) $adminPlan;
        }

        $sharedPlan = UserPaymentPlan::query()
            ->where('level_id', $level->id)
            ->where('currency', $currency)
            ->where('payment_gateway', 'flutterwave')
            ->whereNotNull('payment_plan_id')
            ->latest()
            ->value('payment_plan_id');

        if ($sharedPlan) {
            return (string) $sharedPlan;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$secret,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post("{$baseUrl}/payment-plans", [
            'name' => "Payhankey {$level->name} ({$currency})",
            'amount' => (int) round($amount * 100),
            'currency' => $currency,
            'interval' => 'monthly',
            'duration' => (int) config('flutterwave.checkout.plan_duration_months', 24),
        ]);

        if (! $response->successful()) {
            Log::warning('Flutterwave payment plan creation failed; proceeding without plan', [
                'level_id' => $level->id,
                'currency' => $currency,
                'response' => $response->json(),
            ]);

            return null;
        }

        $planId = (string) data_get($response->json(), 'data.id');

        UserPaymentPlan::updateOrCreate(
            [
                'user_id' => $user->id,
                'level_id' => $level->id,
                'currency' => $currency,
                'payment_gateway' => 'flutterwave',
            ],
            [
                'name' => data_get($response->json(), 'data.name'),
                'payment_plan_id' => $planId,
                'amount' => $amount,
                'interval' => 'monthly',
                'status' => data_get($response->json(), 'data.status'),
                'payment_plan_token' => data_get($response->json(), 'data.plan_token'),
            ],
        );

        return $planId;
    }
}
