<?php

namespace App\Services;

use App\Models\Level;
use App\Models\SubscriptionStat;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserLevel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpgradeSubscriptionService
{
    public function __construct(protected TransactionService $transactionService) {}

    public function upgradeSubscription(User $user, Level $level, Transaction $transaction, array $payload): void
    {
        DB::transaction(function () use ($user, $level, $transaction, $payload) {
            $nextPaymentDate = now()->addMonth();

            $transaction->refresh();

            if ($transaction->status === 'successful') {
                return;
            }

            $this->transactionService->markSuccessful($transaction, [
                'webhook_payload' => $payload,
            ]);

            $user = User::with('wallet')->findOrFail($transaction->user_id);
            $currency = $user->wallet?->currency;

            if (! $currency) {
                throw new \RuntimeException('User wallet currency not found.');
            }

            $upgradeAmount = convertToBaseCurrency((float) $level->amount, $currency);

            UserLevel::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'level_id' => $level->id,
                    'plan_name' => $level->name,
                    'plan_code' => $level->id,
                    'subscription_code' => $level->id,
                    'email_token' => $level->id,
                    'start_date' => now(),
                    'status' => 'active',
                    'next_payment_date' => $nextPaymentDate,
                ],
            );

            $hasReceivedBonus = SubscriptionStat::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->exists();

            if (! $hasReceivedBonus && $user->wallet) {
                $bonus = convertToBaseCurrency((float) $level->reg_bonus, $currency);

                $user->wallet->increment('balance', $bonus);

                Transaction::create([
                    'user_id' => $user->id,
                    'ref' => $transaction->ref.'-bonus',
                    'amount' => $bonus,
                    'currency' => $currency,
                    'status' => 'successful',
                    'type' => 'reg_bonus',
                    'action' => 'Credit',
                    'description' => "Upgrade bonus for {$level->name}",
                ]);
            }

            SubscriptionStat::create([
                'user_id' => $user->id,
                'level_id' => $level->id,
                'plan_name' => $level->name,
                'amount' => $upgradeAmount,
                'currency' => $currency,
                'start_date' => now(),
                'end_date' => $nextPaymentDate,
            ]);

            Log::info('Level upgrade subscription activated', [
                'user_id' => $user->id,
                'level_id' => $level->id,
                'reference' => $transaction->ref,
            ]);
        });
    }
}
