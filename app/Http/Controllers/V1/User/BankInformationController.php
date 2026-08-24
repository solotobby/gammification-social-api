<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WithdrawalMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class BankInformationController extends Controller
{
    /**
     * Form payload + existing payout method.
     * NGN users get Korapay bank list + account_number field.
     */
    public function show(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $currency = userBaseCurrency($user->id) ?: 'NGN';
        $method = WithdrawalMethod::where('user_id', $user->id)->first();

        $form = [
            'currency' => $currency,
            'payment_method' => $currency === 'NGN' ? 'bank_transfer' : ($method?->payment_method),
            'fields' => $currency === 'NGN'
                ? [
                    ['name' => 'bank_code', 'type' => 'select', 'label' => 'Select bank', 'required' => true],
                    ['name' => 'account_number', 'type' => 'text', 'label' => 'Account number', 'required' => true],
                ]
                : [
                    ['name' => 'payment_method', 'type' => 'select', 'label' => 'Payment method', 'required' => true, 'options' => ['paypal', 'usdt']],
                    ['name' => 'paypal_email', 'type' => 'email', 'label' => 'PayPal email', 'required_if' => 'paypal'],
                    ['name' => 'usdt_wallet', 'type' => 'text', 'label' => 'USDT wallet', 'required_if' => 'usdt'],
                ],
        ];

        if ($currency === 'NGN') {
            try {
                $form['banks'] = bankList();
            } catch (Throwable $e) {
                Log::error('Failed to load bank list', ['message' => $e->getMessage()]);

                return response()->json([
                    'success' => false,
                    'message' => 'Unable to load Nigerian bank list right now',
                ], 502);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Bank information',
            'data' => [
                'form' => $form,
                'withdrawal_method' => $method ? $this->present($method) : null,
            ],
        ]);
    }

    /**
     * Create payout details on withdrawal_methods.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        if (WithdrawalMethod::where('user_id', $user->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Payout information already exists. Use update instead.',
            ], 409);
        }

        try {
            $method = $this->persist($request, $user);

            return response()->json([
                'success' => true,
                'message' => 'Bank information saved',
                'data' => $this->present($method),
            ], 201);
        } catch (Throwable $e) {
            Log::error('Failed to save bank information', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Unable to save bank information',
            ], 422);
        }
    }

    /**
     * Update existing payout details.
     */
    public function update(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $existing = WithdrawalMethod::where('user_id', $user->id)->first();
        if (! $existing) {
            return response()->json([
                'success' => false,
                'message' => 'No payout information found. Create it first.',
            ], 404);
        }

        try {
            $method = $this->persist($request, $user, $existing);

            return response()->json([
                'success' => true,
                'message' => 'Bank information updated',
                'data' => $this->present($method),
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to update bank information', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Unable to update bank information',
            ], 422);
        }
    }

    private function persist(Request $request, User $user, ?WithdrawalMethod $existing = null): WithdrawalMethod
    {
        $currency = userBaseCurrency($user->id) ?: 'NGN';

        if ($currency === 'NGN') {
            $validated = $request->validate([
                'bank_code' => ['required', 'string'],
                'account_number' => ['required', 'numeric', 'digits_between:10,12'],
            ]);

            [$bankCode, $bankName] = $this->splitBankSelection($validated['bank_code']);
            $resolved = $this->resolveBankAccount($validated['account_number'], $bankCode);

            $payload = [
                'user_id' => $user->id,
                'country' => 'Nigeria',
                'currency' => 'NGN',
                'payment_method' => 'bank_transfer',
                'bank_code' => $bankCode,
                'bank_name' => $resolved['bank_name'] ?? $bankName,
                'account_number' => $resolved['account_number'] ?? $validated['account_number'],
                'account_name' => $resolved['account_name'] ?? null,
                'recipient_code' => $resolved['recipient_code'] ?? $existing?->recipient_code ?? (string) Str::uuid(),
                'paypal_email' => null,
                'usdt_wallet' => null,
                'is_active' => true,
            ];

            if (! empty($payload['account_name'])) {
                $user->update(['name' => $payload['account_name']]);
            }
        } else {
            $validated = $request->validate([
                'payment_method' => ['required', 'in:paypal,usdt'],
                'paypal_email' => ['required_if:payment_method,paypal', 'nullable', 'email'],
                'usdt_wallet' => ['required_if:payment_method,usdt', 'nullable', 'string'],
            ]);

            $payload = [
                'user_id' => $user->id,
                'country' => $existing?->country ?: 'International',
                'currency' => $currency,
                'payment_method' => $validated['payment_method'],
                'paypal_email' => $validated['payment_method'] === 'paypal' ? $validated['paypal_email'] : null,
                'usdt_wallet' => $validated['payment_method'] === 'usdt' ? $validated['usdt_wallet'] : null,
                'bank_code' => null,
                'bank_name' => null,
                'account_number' => null,
                'account_name' => null,
                'is_active' => true,
            ];
        }

        if ($existing) {
            $existing->update($payload);

            return $existing->fresh();
        }

        return WithdrawalMethod::create($payload);
    }

    /**
     * Accepts either "code, Bank Name" (web form) or a plain bank code.
     *
     * @return array{0: string, 1: string|null}
     */
    private function splitBankSelection(string $value): array
    {
        if (str_contains($value, ',')) {
            [$code, $name] = array_map('trim', explode(',', $value, 2));

            return [$code, $name !== '' ? $name : null];
        }

        return [trim($value), null];
    }

    private function resolveBankAccount(string $accountNumber, string $bankCode): array
    {
        $url = rtrim((string) config('services.env.kora_base_url', 'https://api.korapay.com/merchant/api/v1'), '/')
            .'/misc/banks/resolve';

        $res = Http::withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.config('services.env.kora_pub'),
        ])->post($url, [
            'bank' => $bankCode,
            'account' => $accountNumber,
        ])->throw();

        $data = json_decode($res->getBody()->getContents(), true)['data'] ?? null;

        if (! is_array($data)) {
            throw new \RuntimeException('Unable to resolve bank account');
        }

        return $data;
    }

    private function present(WithdrawalMethod $method): array
    {
        return [
            'id' => $method->id,
            'currency' => $method->currency,
            'payment_method' => $method->payment_method,
            'bank_code' => $method->bank_code,
            'bank_name' => $method->bank_name,
            'account_number' => $method->account_number,
            'account_name' => $method->account_name,
            'paypal_email' => $method->paypal_email,
            'usdt_wallet' => $method->usdt_wallet,
            'is_active' => (bool) $method->is_active,
        ];
    }
}
