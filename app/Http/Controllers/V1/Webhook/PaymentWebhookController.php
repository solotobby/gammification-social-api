<?php

namespace App\Http\Controllers\V1\Webhook;

use App\Http\Controllers\Controller;
use App\Models\ApiResponse;
use App\Models\Level;
use App\Models\Transaction;
use App\Services\TransactionService;
use App\Services\UpgradeSubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    public function __construct(
        protected TransactionService $transactionService,
        protected UpgradeSubscriptionService $upgradeSubscriptionService,
    ) {}

    public function flutterwave(Request $request)
    {
        $signature = $request->header('verif-hash');
        $secretHash = (string) config('services.env.flutterwave_webhook_hash');

        if (! $signature || ! $secretHash || ! hash_equals($secretHash, $signature)) {
            Log::warning('Invalid Flutterwave webhook signature', [
                'ip' => $request->ip(),
            ]);

            abort(401, 'Invalid signature.');
        }

        ApiResponse::create(['response' => $request->all()]);

        $payload = $request->all();
        $event = data_get($payload, 'event');

        if ($event !== 'charge.completed') {
            return response('Webhook ignored', 200);
        }

        $gatewayData = data_get($payload, 'data', []);
        $txRef = (string) data_get($gatewayData, 'tx_ref');
        $gatewayStatus = strtolower((string) data_get($gatewayData, 'status'));
        $gatewayAmount = (float) data_get($gatewayData, 'amount');
        $gatewayCurrency = strtoupper((string) data_get($gatewayData, 'currency'));

        if ($gatewayStatus !== 'successful') {
            return response('Webhook ignored', 200);
        }

        if (! str_starts_with(strtoupper($txRef), 'PKY-')) {
            Log::info('Flutterwave webhook ignored for non-upgrade reference', [
                'tx_ref' => $txRef,
            ]);

            return response('Webhook ignored', 200);
        }

        return DB::transaction(function () use ($txRef, $gatewayAmount, $gatewayCurrency, $payload, $gatewayData) {
            $transaction = Transaction::query()
                ->where('ref', $txRef)
                ->lockForUpdate()
                ->first();

            if (! $transaction) {
                Log::error('Flutterwave webhook transaction not found', [
                    'tx_ref' => $txRef,
                ]);

                return response('Transaction not found', 404);
            }

            if ($transaction->status === 'successful') {
                return response('Webhook processed', 200);
            }

            if (abs((float) $transaction->amount - $gatewayAmount) > 0.01) {
                Log::error('Flutterwave webhook amount mismatch', [
                    'tx_ref' => $txRef,
                    'expected' => $transaction->amount,
                    'received' => $gatewayAmount,
                ]);

                return response('Amount mismatch', 422);
            }

            if (strtoupper((string) $transaction->currency) !== $gatewayCurrency) {
                Log::error('Flutterwave webhook currency mismatch', [
                    'tx_ref' => $txRef,
                    'expected' => $transaction->currency,
                    'received' => $gatewayCurrency,
                ]);

                return response('Currency mismatch', 422);
            }

            $level = Level::query()->find($transaction->meta['level_id'] ?? null);

            if (! $level) {
                Log::error('Level not found for PKY transaction', [
                    'tx_ref' => $txRef,
                    'transaction_id' => $transaction->id,
                ]);

                return response('Level not found', 422);
            }

            $this->upgradeSubscriptionService->upgradeSubscription(
                $transaction->user,
                $level,
                $transaction,
                $payload,
            );

            Log::info('Flutterwave level upgrade webhook processed', [
                'tx_ref' => $txRef,
                'transaction_id' => $transaction->id,
            ]);

            return response('Webhook processed', 200);
        });
    }
}
