<?php

namespace App\Http\Controllers\V1\PayKoin;

use App\Http\Controllers\Controller;
use App\Models\PaykoinTransaction;
use App\Services\PayKoinService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PayKoinController extends Controller
{
    public function __construct(
        protected PayKoinService $payKoinService,
    ) {}

    /**
     * GET /v1/paykoin/balance — get authenticated user's PayKoin balances and current currency rates.
     */
    public function balance(Request $request)
    {
        return $this->respond($request, function () use ($request) {
            $user = resolveApiUser($request);
            $wallet = $this->payKoinService->ensureUserWallet($user);
            $currency = strtoupper((string) ($wallet->currency ?: 'NGN'));

            $rates = null;
            try {
                $rates = $this->payKoinService->rates($currency);
            } catch (Throwable) {
                $rates = config('payhankey.paykoin.rates.NGN');
            }

            return [
                'message' => 'PayKoin balance retrieved successfully',
                'data' => [
                    'paykoin_spendable' => (int) $wallet->paykoin_spendable,
                    'paykoin_earned' => (int) $wallet->paykoin_earned,
                    'currency' => $currency,
                    'min_top_up' => $this->payKoinService->minTopUp($currency),
                    'rates' => $rates,
                ],
            ];
        });
    }

    /**
     * GET /v1/paykoin/rates — get exchange and conversion rates for a currency.
     */
    public function rates(Request $request)
    {
        $currency = strtoupper((string) ($request->query('currency')
            ?: ($request->user() ? userBaseCurrency($request->user()->id) : null)
            ?: 'NGN'));

        try {
            $rates = $this->payKoinService->rates($currency);
            $minTopUp = $this->payKoinService->minTopUp($currency);

            return response()->json([
                'success' => true,
                'message' => 'PayKoin rates retrieved successfully',
                'data' => [
                    'currency' => $currency,
                    'rates' => $rates,
                    'min_top_up' => $minTopUp,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /v1/paykoin/topup — initialize PayKoin purchase checkout.
     */
    public function topup(Request $request)
    {
        return $this->respond($request, function () use ($request) {
            $request->validate([
                'amount' => ['required', 'numeric', 'min:1'],
                'redirect_url' => ['nullable', 'url'],
            ]);

            $user = resolveApiUser($request);
            $amount = (float) $request->input('amount');
            $redirectUrl = $request->input('redirect_url');

            $checkoutUrl = $this->payKoinService->initiateTopUp($user, $amount, $redirectUrl);

            return [
                'message' => 'PayKoin top-up initialized successfully',
                'data' => [
                    'checkout_url' => $checkoutUrl,
                ],
            ];
        });
    }

    /**
     * GET /v1/paykoin/topup/status — check status of PayKoin top-up by reference.
     */
    public function topupStatus(Request $request)
    {
        return $this->respond($request, function () use ($request) {
            $request->validate([
                'reference' => ['required', 'string'],
            ]);

            $status = $this->payKoinService->acknowledgeTopUpReturn($request->input('reference'));

            return [
                'message' => $status['message'],
                'data' => $status,
            ];
        });
    }

    /**
     * POST /v1/paykoin/convert — convert earned PayKoin to fiat wallet balance.
     */
    public function convert(Request $request)
    {
        return $this->respond($request, function () use ($request) {
            $request->validate([
                'amount' => ['required', 'integer', 'min:1'],
            ]);

            $user = resolveApiUser($request);
            $amount = (int) $request->input('amount');

            $tx = $this->payKoinService->convertEarned($user, $amount);
            $wallet = $user->fresh()->wallet;

            return [
                'message' => 'PayKoin converted to wallet cash successfully',
                'data' => [
                    'transaction' => $tx,
                    'paykoin_earned' => (int) $wallet->paykoin_earned,
                    'paykoin_spendable' => (int) $wallet->paykoin_spendable,
                    'wallet_balance' => (float) $wallet->balance,
                ],
            ];
        });
    }

    /**
     * GET /v1/paykoin/transactions — user PayKoin transaction history.
     */
    public function transactions(Request $request)
    {
        return $this->respond($request, function () use ($request) {
            $user = resolveApiUser($request);
            $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

            $transactions = PaykoinTransaction::query()
                ->where('user_id', $user->id)
                ->latest()
                ->paginate($perPage);

            return [
                'message' => 'Transactions retrieved successfully',
                'data' => $transactions,
            ];
        });
    }

    /**
     * @param  callable(): array{message: string, data: mixed}  $callback
     */
    private function respond(Request $request, callable $callback)
    {
        $user = resolveApiUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        try {
            $payload = $callback();

            return response()->json([
                'success' => true,
                'message' => $payload['message'],
                'data' => $payload['data'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->validator->errors()->first(),
                'errors' => $e->validator->errors(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Record not found',
            ], 404);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('PayKoin action failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Unable to process PayKoin request',
            ], 500);
        }
    }
}
