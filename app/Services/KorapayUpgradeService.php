<?php

namespace App\Services;

use App\Models\Level;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class KorapayUpgradeService
{
    public function __construct(protected TransactionService $transactionService) {}

    /**
     * @return array{checkout_url: string, reference: string, transaction_id: string, amount: float, currency: string}
     */
    public function initiateCheckout(
        User $user,
        Level $level,
        float $amountNgn,
        string $billingMode,
        ?string $idempotencyKey = null,
    ): array {
        if ($amountNgn <= 0) {
            throw new InvalidArgumentException('This level does not require payment.');
        }

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
            ];
        }

        $reference = generateTransactionRef();
        $secret = config('services.env.kora_sec');

        if (! $secret) {
            throw new RuntimeException('Korapay is not configured.');
        }

        $transaction = $this->transactionService->createTransaction(
            user: $user,
            idempotencyKey: $idempotencyKey,
            provider: 'korapay',
            reference: $reference,
            amount: $amountNgn,
            currency: 'NGN',
            status: 'initiated',
            action: 'Debit',
            type: 'subscription_upgrade',
            description: "{$user->name} upgrade to {$level->name}",
            meta: [
                'level_id' => $level->id,
                'level_name' => $level->name,
                'billing_mode' => $billingMode,
            ],
        );

        $payload = [
            'amount' => $amountNgn,
            'redirect_url' => config('services.payment.korapay_redirect_url'),
            'currency' => 'NGN',
            'reference' => $reference,
            'narration' => "{$level->name} upgrade",
            'channels' => ['card', 'bank_transfer', 'pay_with_bank'],
            'customer' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
            'metadata' => [
                'user_id' => $user->id,
                'level_id' => $level->id,
                'billing_mode' => $billingMode,
            ],
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$secret,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->post(
            rtrim((string) config('services.env.kora_base_url'), '/').'/charges/initialize',
            $payload,
        );

        if (! $response->successful()) {
            $transaction->update(['status' => 'failed', 'meta' => array_merge($transaction->meta ?? [], [
                'gateway_response' => $response->json(),
            ])]);

            Log::error('Korapay upgrade init failed', [
                'reference' => $reference,
                'response' => $response->json(),
            ]);

            throw new RuntimeException('Unable to initialize Korapay checkout.');
        }

        $checkoutUrl = data_get($response->json(), 'data.checkout_url');

        if (! $checkoutUrl) {
            $transaction->update(['status' => 'failed']);
            throw new RuntimeException('Korapay checkout URL missing.');
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
            'amount' => $amountNgn,
            'currency' => 'NGN',
        ];
    }
}
