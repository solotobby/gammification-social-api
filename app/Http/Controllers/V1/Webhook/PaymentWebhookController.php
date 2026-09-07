<?php

namespace App\Http\Controllers\V1\Webhook;

use App\Http\Controllers\Controller;
use App\Models\ApiResponse;
use App\Models\Community;
use App\Models\Level;
use App\Models\Transaction;
use App\Services\CommunitySubscriptionService;
use App\Services\PayKoinService;
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
        protected CommunitySubscriptionService $communitySubscriptionService,
        protected PayKoinService $payKoinService,
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

        $isLevelUpgrade = str_starts_with(strtoupper($txRef), 'PKY-');
        $isCommunity = str_starts_with(strtoupper($txRef), 'COM-') || str_starts_with(strtoupper($txRef), 'COMM-');

        if (! $isLevelUpgrade && ! $isCommunity) {
            Log::info('Flutterwave webhook ignored for unsupported reference prefix', [
                'tx_ref' => $txRef,
            ]);

            return response('Webhook ignored', 200);
        }

        return DB::transaction(function () use ($txRef, $gatewayAmount, $gatewayCurrency, $payload, $gatewayData, $isLevelUpgrade, $isCommunity) {
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

            if ($isLevelUpgrade) {
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
            }

            if ($isCommunity) {
                $communityId = $transaction->meta['community_id'] ?? null;
                $community = $communityId ? Community::query()->find($communityId) : null;

                if (! $community) {
                    Log::error('Community not found for COM transaction', [
                        'tx_ref' => $txRef,
                        'transaction_id' => $transaction->id,
                        'community_id' => $communityId,
                    ]);

                    return response('Community not found', 422);
                }

                $this->communitySubscriptionService->processSuccessfulPayment(
                    $community,
                    $transaction->user,
                    $transaction,
                    (string) data_get($gatewayData, 'id', $txRef),
                    $payload,
                );

                Log::info('Flutterwave community subscription webhook processed', [
                    'tx_ref' => $txRef,
                    'transaction_id' => $transaction->id,
                    'community_id' => $community->id,
                ]);

                return response('Webhook processed', 200);
            }

            return response('Webhook ignored', 200);
        });
    }

    public function korapay(Request $request)
    {
        $signature = $request->header('x-korapay-signature');
        $secretKey = (string) config('services.env.kora_sec');

        if ($signature && $secretKey) {
            $expected = hash_hmac('sha256', json_encode($request->input('data', [])), $secretKey);
            if (! hash_equals($expected, (string) $signature)) {
                Log::warning('Invalid Korapay webhook signature', [
                    'ip' => $request->ip(),
                ]);

                abort(401, 'Invalid signature.');
            }
        }

        ApiResponse::create(['response' => $request->all()]);

        $payload = $request->all();
        $event = data_get($payload, 'event');

        if ($event !== 'charge.success') {
            return response('Webhook ignored', 200);
        }

        $gatewayData = data_get($payload, 'data', []);
        $reference = (string) data_get($gatewayData, 'reference');
        $gatewayStatus = strtolower((string) data_get($gatewayData, 'status'));
        $gatewayAmount = (float) data_get($gatewayData, 'amount');
        $gatewayCurrency = strtoupper((string) data_get($gatewayData, 'currency'));

        if ($gatewayStatus !== 'success') {
            return response('Webhook ignored', 200);
        }

        $isLevelUpgrade = str_starts_with(strtoupper($reference), 'PKY-');
        $isCommunity = str_starts_with(strtoupper($reference), 'COM-') || str_starts_with(strtoupper($reference), 'COMM-');
        $isPayKoin = str_starts_with(strtoupper($reference), 'PKN-');

        if (! $isLevelUpgrade && ! $isCommunity && ! $isPayKoin) {
            Log::info('Korapay webhook ignored for unsupported reference prefix', [
                'reference' => $reference,
            ]);

            return response('Webhook ignored', 200);
        }

        return DB::transaction(function () use ($reference, $gatewayAmount, $gatewayCurrency, $payload, $isLevelUpgrade, $isCommunity, $isPayKoin) {
            $transaction = Transaction::query()
                ->where('ref', $reference)
                ->lockForUpdate()
                ->first();

            if (! $transaction) {
                Log::error('Korapay webhook transaction not found', [
                    'reference' => $reference,
                ]);

                return response('Transaction not found', 404);
            }

            if ($transaction->status === 'successful') {
                return response('Webhook processed', 200);
            }

            if ($isPayKoin) {
                try {
                    $this->payKoinService->creditTopUpFromWebhook($transaction, $payload);

                    Log::info('Korapay PayKoin topup webhook processed', [
                        'reference' => $reference,
                        'transaction_id' => $transaction->id,
                    ]);

                    return response('Webhook processed', 200);
                } catch (\Throwable $e) {
                    Log::error('Korapay PayKoin topup failed', [
                        'reference' => $reference,
                        'error' => $e->getMessage(),
                    ]);

                    return response($e->getMessage(), 422);
                }
            }

            if (abs((float) $transaction->amount - $gatewayAmount) > 0.01) {
                Log::error('Korapay webhook amount mismatch', [
                    'reference' => $reference,
                    'expected' => $transaction->amount,
                    'received' => $gatewayAmount,
                ]);

                return response('Amount mismatch', 422);
            }

            if (strtoupper((string) $transaction->currency) !== $gatewayCurrency) {
                Log::error('Korapay webhook currency mismatch', [
                    'reference' => $reference,
                    'expected' => $transaction->currency,
                    'received' => $gatewayCurrency,
                ]);

                return response('Currency mismatch', 422);
            }

            if ($isLevelUpgrade) {
                $level = Level::query()->find($transaction->meta['level_id'] ?? null);

                if (! $level) {
                    Log::error('Level not found for PKY transaction', [
                        'reference' => $reference,
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

                Log::info('Korapay level upgrade webhook processed', [
                    'reference' => $reference,
                    'transaction_id' => $transaction->id,
                ]);

                return response('Webhook processed', 200);
            }

            if ($isCommunity) {
                $communityId = $transaction->meta['community_id'] ?? null;
                $community = $communityId ? Community::query()->find($communityId) : null;

                if (! $community) {
                    Log::error('Community not found for COM transaction', [
                        'reference' => $reference,
                        'transaction_id' => $transaction->id,
                        'community_id' => $communityId,
                    ]);

                    return response('Community not found', 422);
                }

                $this->communitySubscriptionService->processSuccessfulPayment(
                    $community,
                    $transaction->user,
                    $transaction,
                    $reference,
                    $payload,
                );

                Log::info('Korapay community subscription webhook processed', [
                    'reference' => $reference,
                    'transaction_id' => $transaction->id,
                    'community_id' => $community->id,
                ]);

                return response('Webhook processed', 200);
            }

            return response('Webhook ignored', 200);
        });
    }
}
