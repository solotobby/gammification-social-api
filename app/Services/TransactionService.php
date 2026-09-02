<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;

class TransactionService
{
    public function createTransaction(
        User $user,
        string $idempotencyKey,
        string $provider,
        string $reference,
        float $amount,
        string $currency,
        string $status,
        string $action,
        string $type,
        string $description,
        array $meta = [],
        ?array $customer = null,
    ): Transaction {
        return Transaction::create([
            'user_id' => $user->id,
            'idempotency_key' => $idempotencyKey,
            'provider' => $provider,
            'ref' => $reference,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $status,
            'type' => $type,
            'action' => $action,
            'description' => $description,
            'meta' => $meta,
            'customer' => $customer,
        ]);
    }

    public function markSuccessful(Transaction $transaction, array $data = []): void
    {
        $transaction->update([
            'status' => 'successful',
            'meta' => array_merge($transaction->meta ?? [], $data),
        ]);
    }

    public function markFailed(Transaction $transaction, array $data = []): void
    {
        $transaction->update([
            'status' => 'failed',
            'meta' => array_merge($transaction->meta ?? [], $data),
        ]);
    }

    public function markProcessing(Transaction $transaction, array $data = []): void
    {
        $transaction->update([
            'status' => 'processing',
            'meta' => array_merge($transaction->meta ?? [], $data),
        ]);
    }

    public function markCancelled(Transaction $transaction, array $data = []): void
    {
        $transaction->update([
            'status' => 'cancelled',
            'meta' => array_merge($transaction->meta ?? [], $data),
        ]);
    }
}
