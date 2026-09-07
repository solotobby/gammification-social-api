<?php

namespace App\Services;

use App\Models\CommunityPost;
use App\Models\PaykoinTransaction;
use App\Models\Post;
use App\Models\PostGift;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;

class PayKoinService
{
    public function __construct(
        protected TransactionService $transactionService,
    ) {}

    public function rates(string $currency): array
    {
        $currency = strtoupper($currency);
        $rates = config("payhankey.paykoin.rates.{$currency}");

        if (! $rates) {
            throw new RuntimeException('PayKoin is not configured for your currency.');
        }

        return $rates;
    }

    public function minTopUp(string $currency): float
    {
        return (float) config('payhankey.paykoin.min_top_up', 100);
    }

    public function pkFromCash(float $cash, string $currency): int
    {
        $listRate = (float) $this->rates($currency)['list'];

        return (int) floor($cash / $listRate);
    }

    public function fiatFromPk(int $pk, string $currency): float
    {
        $convertRate = (float) $this->rates($currency)['convert'];

        return round($pk * $convertRate, 2);
    }

    public function initiateTopUp(User $user, float $cashAmount, ?string $redirectUrl = null): string
    {
        $wallet = $user->wallet ?? throw new RuntimeException('Wallet not found.');
        $currency = strtoupper((string) $wallet->currency);
        $min = $this->minTopUp($currency);

        if ($cashAmount < $min) {
            throw new RuntimeException('Minimum top-up is '.number_format($min).' '.$currency.'.');
        }

        $pkAmount = $this->pkFromCash($cashAmount, $currency);

        if ($pkAmount < 1) {
            throw new RuntimeException('Amount is too low to receive PayKoin.');
        }

        $reference = generateTransactionRef('PKN');
        $idempotencyKey = Str::uuid()->toString();

        $chargeCurrency = 'NGN';
        $chargeAmount = $currency === 'NGN'
            ? $cashAmount
            : convertToBaseCurrency($cashAmount, 'NGN');

        $this->transactionService->createTransaction(
            user: $user,
            idempotencyKey: $idempotencyKey,
            provider: 'kora',
            reference: $reference,
            amount: $cashAmount,
            currency: $currency,
            status: 'initiated',
            action: 'Debit',
            type: 'paykoin_topup',
            description: 'PayKoin top-up',
            meta: [
                'pk_amount' => $pkAmount,
                'cash_amount' => $cashAmount,
                'charge_amount_ngn' => $chargeAmount,
                'charge_currency' => $chargeCurrency,
            ],
        );

        $redirect = $redirectUrl ?: (Route::has('verify.paykoin.topup')
            ? route('verify.paykoin.topup')
            : url('/v1/paykoin/topup/status?reference='.$reference));

        $notification = Route::has('korapay.webhook')
            ? route('korapay.webhook')
            : url('/v1/webhooks/korapay');

        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.config('services.env.kora_sec'),
        ])->post('https://api.korapay.com/merchant/api/v1/charges/initialize', [
            'amount' => $chargeAmount,
            'redirect_url' => $redirect,
            'notification_url' => $notification,
            'currency' => $chargeCurrency,
            'reference' => $reference,
            'narration' => 'PayKoin top-up',
            'channels' => ['card', 'bank_transfer', 'pay_with_bank'],
            'customer' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
            'metadata' => [
                'user_id' => $user->id,
                'type' => 'paykoin_topup',
                'pk_amount' => $pkAmount,
            ],
        ]);

        if (! $response->successful()) {
            Transaction::where('ref', $reference)->update(['status' => 'failed']);
            throw new RuntimeException('Unable to initialize payment.');
        }

        $url = $response->json('data.checkout_url');

        if (! $url) {
            throw new RuntimeException('Payment provider did not return a checkout URL.');
        }

        return $url;
    }

    /**
     * Return/status handler only — never credits PayKoin here.
     */
    public function acknowledgeTopUpReturn(string $reference): array
    {
        if (! str_starts_with(strtoupper($reference), 'PKN-')) {
            throw new RuntimeException('Invalid PayKoin payment reference.');
        }

        $transaction = Transaction::where('ref', $reference)
            ->where('type', 'paykoin_topup')
            ->firstOrFail();

        $paykoinTx = PaykoinTransaction::where('ref', $reference)->first();

        if ($paykoinTx) {
            return [
                'status' => 'credited',
                'message' => number_format($paykoinTx->pk_amount).' PayKoin has been added to your balance.',
                'pk_amount' => (int) $paykoinTx->pk_amount,
            ];
        }

        if (in_array($transaction->status, ['failed', 'cancelled', 'flagged'], true)) {
            return [
                'status' => 'failed',
                'message' => 'Payment was not successful. Please try again.',
                'pk_amount' => 0,
            ];
        }

        return [
            'status' => 'pending',
            'message' => 'Payment received. Your PayKoin will be credited shortly once confirmed.',
            'pk_amount' => 0,
        ];
    }

    /**
     * Credit PayKoin only from a verified webhook (charge.success).
     */
    public function creditTopUpFromWebhook(Transaction $transaction, array $payload): PaykoinTransaction
    {
        if ($transaction->type !== 'paykoin_topup') {
            throw new RuntimeException('Invalid PayKoin transaction.');
        }

        if (! str_starts_with(strtoupper($transaction->ref), 'PKN-')) {
            throw new RuntimeException('Invalid PayKoin payment reference.');
        }

        $event = $payload['event'] ?? null;
        $data = $payload['data'] ?? [];

        if ($event !== 'charge.success' || ($data['status'] ?? '') !== 'success') {
            throw new RuntimeException('Webhook event is not a successful charge.');
        }

        if (($data['reference'] ?? '') !== $transaction->ref) {
            $this->transactionService->markFailed($transaction, $payload);
            throw new RuntimeException('Payment reference mismatch.');
        }

        if (! $this->webhookAmountMatches($transaction, $data)) {
            $this->transactionService->markFailed($transaction, $payload);
            throw new RuntimeException('Payment amount mismatch.');
        }

        $pkAmount = (int) ($transaction->meta['pk_amount'] ?? 0);

        if ($pkAmount < 1) {
            throw new RuntimeException('Invalid PayKoin amount on transaction.');
        }

        return DB::transaction(function () use ($transaction, $payload, $pkAmount) {
            $locked = Transaction::where('id', $transaction->id)->lockForUpdate()->firstOrFail();

            $existing = PaykoinTransaction::where('ref', $locked->ref)->first();
            if ($existing) {
                return $existing;
            }

            $wallet = $this->ensureUserWallet($locked->user);

            $wallet->paykoin_spendable = (int) $wallet->paykoin_spendable + $pkAmount;
            $wallet->save();

            $this->transactionService->markSuccessful($locked, $payload);

            return PaykoinTransaction::create([
                'user_id' => $locked->user_id,
                'type' => 'topup',
                'pk_amount' => $pkAmount,
                'fiat_amount' => (float) $locked->amount,
                'currency' => $locked->currency,
                'ref' => $locked->ref,
                'description' => 'PayKoin top-up',
                'meta' => ['provider' => 'kora', 'source' => 'webhook'],
            ]);
        });
    }

    protected function webhookAmountMatches(Transaction $transaction, array $data): bool
    {
        $gatewayAmount = (float) ($data['amount'] ?? 0);
        $expected = (float) ($transaction->meta['charge_amount_ngn'] ?? $transaction->amount);

        if ($gatewayAmount <= 0 || $expected <= 0) {
            return false;
        }

        // Korapay webhooks may send kobo; normalize if clearly scaled.
        if ($gatewayAmount > $expected * 50) {
            $gatewayAmount /= 100;
        }

        return abs($gatewayAmount - $expected) <= 0.02
            && strtoupper((string) ($data['currency'] ?? 'NGN')) === 'NGN';
    }

    public function convertEarned(User $user, int $pkAmount): PaykoinTransaction
    {
        if ($pkAmount < 1) {
            throw new RuntimeException('Enter a valid PayKoin amount.');
        }

        $wallet = $user->wallet ?? throw new RuntimeException('Wallet not found.');
        $currency = strtoupper((string) $wallet->currency);

        if ($pkAmount > (int) $wallet->paykoin_earned) {
            throw new RuntimeException('You can only convert PayKoin earned from gifts.');
        }

        $fiatAmount = $this->fiatFromPk($pkAmount, $currency);
        $reference = generateTransactionRef('PKN');

        return DB::transaction(function () use ($user, $wallet, $pkAmount, $fiatAmount, $currency, $reference) {
            $locked = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            if ($pkAmount > (int) $locked->paykoin_earned) {
                throw new RuntimeException('Insufficient gift earnings to convert.');
            }

            $locked->paykoin_earned -= $pkAmount;
            $locked->balance = round((float) $locked->balance + $fiatAmount, 2);
            $locked->save();

            return PaykoinTransaction::create([
                'user_id' => $user->id,
                'type' => 'convert',
                'pk_amount' => -$pkAmount,
                'fiat_amount' => $fiatAmount,
                'currency' => $currency,
                'ref' => $reference,
                'description' => 'Converted gift earnings to wallet',
                'meta' => [],
            ]);
        });
    }

    public function recentTransactions(User $user, int $limit = 50)
    {
        return PaykoinTransaction::where('user_id', $user->id)
            ->latest()
            ->limit($limit)
            ->get();
    }

    public function giftArtifacts(): array
    {
        return config('payhankey.paykoin.gift_artifacts', []);
    }

    public function findArtifact(string $artifactId): ?array
    {
        foreach ($this->giftArtifacts() as $artifact) {
            if (($artifact['id'] ?? '') === $artifactId) {
                return $artifact;
            }
        }

        return null;
    }

    /**
     * @return array{total: int, recent: array<int, array<string, mixed>>}
     */
    public function giftsFor(string $giftableType, string $giftableId, int $limit = 20): array
    {
        $modelClass = $this->resolveGiftableClass($giftableType);

        $gifts = PostGift::query()
            ->where('giftable_type', $modelClass)
            ->where('giftable_id', $giftableId)
            ->with('sender:id,username')
            ->latest()
            ->limit($limit)
            ->get();

        $total = (int) PostGift::query()
            ->where('giftable_type', $modelClass)
            ->where('giftable_id', $giftableId)
            ->count();

        return [
            'total' => $total,
            'recent' => $gifts->map(fn (PostGift $gift) => $this->formatGiftForUi($gift))->values()->all(),
        ];
    }

    /**
     * @param  array<int, string>  $giftableIds
     * @return array<string, array{total: int, recent: array<int, array<string, mixed>>}>
     */
    public function giftSummariesForIds(string $giftableType, array $giftableIds): array
    {
        if ($giftableIds === []) {
            return [];
        }

        $modelClass = $this->resolveGiftableClass($giftableType);

        $totals = PostGift::query()
            ->selectRaw('giftable_id, COUNT(*) as total')
            ->where('giftable_type', $modelClass)
            ->whereIn('giftable_id', $giftableIds)
            ->groupBy('giftable_id')
            ->pluck('total', 'giftable_id');

        $recent = PostGift::query()
            ->where('giftable_type', $modelClass)
            ->whereIn('giftable_id', $giftableIds)
            ->with('sender:id,username')
            ->latest()
            ->get()
            ->groupBy('giftable_id');

        $summaries = [];

        foreach ($giftableIds as $id) {
            $summaries[$id] = [
                'total' => (int) ($totals[$id] ?? 0),
                'recent' => ($recent[$id] ?? collect())
                    ->take(4)
                    ->map(fn (PostGift $gift) => $this->formatGiftForUi($gift))
                    ->values()
                    ->all(),
            ];
        }

        return $summaries;
    }

    /**
     * @return array<string, mixed>
     */
    public function validateCanSendGift(User $sender, string $artifactId, string $giftableType, string $giftableId): array
    {
        $artifact = $this->findArtifact($artifactId);

        if (! $artifact) {
            throw new RuntimeException('Unknown gift artifact.');
        }

        $pkAmount = (int) ($artifact['price'] ?? 0);

        if ($pkAmount < 1) {
            throw new RuntimeException('Invalid gift price.');
        }

        $giftable = $this->resolveGiftable($giftableType, $giftableId);
        $recipient = $giftable->user ?? throw new RuntimeException('Post creator not found.');

        if ($recipient->id === $sender->id) {
            throw new RuntimeException('You cannot gift your own post.');
        }

        $this->assertRecipientCanReceiveGifts($recipient);

        if ($giftable instanceof CommunityPost) {
            $giftable->loadMissing('community');

            if (in_array($giftable->community->type, ['private', 'paid', 'approval'], true)
                && ! $giftable->community->members()->where('users.id', $sender->id)->exists()) {
                throw new RuntimeException('Join this community to send gifts.');
            }
        }

        $sender->loadMissing('wallet');
        $spendable = (int) ($sender->wallet?->paykoin_spendable ?? 0);

        if ($pkAmount > $spendable) {
            throw new RuntimeException('Not enough PayKoin to send this gift.');
        }

        $giftTotal = (int) PostGift::query()
            ->where('giftable_type', $giftable::class)
            ->where('giftable_id', $giftable->id)
            ->count();

        return [
            'artifact' => $artifact,
            'pk_amount' => $pkAmount,
            'spendable_after' => $spendable - $pkAmount,
            'gift_total_after' => $giftTotal + 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sendGift(User $sender, string $artifactId, string $giftableType, string $giftableId): array
    {
        $artifact = $this->findArtifact($artifactId);

        if (! $artifact) {
            throw new RuntimeException('Unknown gift artifact.');
        }

        $pkAmount = (int) ($artifact['price'] ?? 0);

        if ($pkAmount < 1) {
            throw new RuntimeException('Invalid gift price.');
        }

        $giftable = $this->resolveGiftable($giftableType, $giftableId);
        $recipient = $giftable->user ?? throw new RuntimeException('Post creator not found.');

        if ($recipient->id === $sender->id) {
            throw new RuntimeException('You cannot gift your own post.');
        }

        $this->assertRecipientCanReceiveGifts($recipient);

        if ($giftable instanceof CommunityPost) {
            $giftable->loadMissing('community');

            if (in_array($giftable->community->type, ['private', 'paid', 'approval'], true)
                && ! $giftable->community->members()->where('users.id', $sender->id)->exists()) {
                throw new RuntimeException('Join this community to send gifts.');
            }
        }

        $reference = generateTransactionRef('PKN');

        return DB::transaction(function () use ($sender, $recipient, $artifact, $artifactId, $pkAmount, $giftable, $giftableType, $reference) {
            $senderWallet = $this->ensureUserWallet($sender);
            $recipientWallet = $this->ensureUserWallet($recipient);

            if ($pkAmount > (int) $senderWallet->paykoin_spendable) {
                throw new RuntimeException('Not enough PayKoin to send this gift.');
            }

            $senderWallet->paykoin_spendable -= $pkAmount;
            $senderWallet->save();

            $recipientWallet->paykoin_earned = (int) $recipientWallet->paykoin_earned + $pkAmount;
            $recipientWallet->save();

            $gift = PostGift::create([
                'sender_id' => $sender->id,
                'recipient_id' => $recipient->id,
                'giftable_type' => $giftable::class,
                'giftable_id' => $giftable->id,
                'artifact_id' => $artifactId,
                'pk_amount' => $pkAmount,
                'ref' => $reference,
                'meta' => [
                    'name' => $artifact['name'],
                    'emoji' => $artifact['emoji'],
                    'tier' => $artifact['tier'] ?? 'classic',
                ],
            ]);

            if ($giftable instanceof CommunityPost || $giftable instanceof Post) {
                $giftable->increment('gifts_count');
            }

            PaykoinTransaction::create([
                'user_id' => $sender->id,
                'type' => 'gift_sent',
                'pk_amount' => -$pkAmount,
                'fiat_amount' => null,
                'currency' => $senderWallet->currency,
                'ref' => $reference,
                'description' => 'Sent '.$artifact['name'].' gift',
                'meta' => [
                    'artifact_id' => $artifactId,
                    'gift_id' => $gift->id,
                    'giftable_type' => $giftableType,
                    'giftable_id' => $giftable->id,
                ],
            ]);

            PaykoinTransaction::create([
                'user_id' => $recipient->id,
                'type' => 'gift_received',
                'pk_amount' => $pkAmount,
                'fiat_amount' => null,
                'currency' => $recipientWallet->currency,
                'ref' => $reference,
                'description' => 'Received '.$artifact['name'].' gift',
                'meta' => [
                    'artifact_id' => $artifactId,
                    'gift_id' => $gift->id,
                    'sender_id' => $sender->id,
                    'giftable_type' => $giftableType,
                    'giftable_id' => $giftable->id,
                ],
            ]);

            return [
                'gift' => $this->formatGiftForUi($gift->load('sender:id,username')),
                'spendable' => (int) $senderWallet->paykoin_spendable,
                'giftTotal' => (int) PostGift::query()
                    ->where('giftable_type', $giftable::class)
                    ->where('giftable_id', $giftable->id)
                    ->count(),
            ];
        });
    }

    public function resolveGiftableClass(string $giftableType): string
    {
        return match ($giftableType) {
            'community_post', 'community', CommunityPost::class => CommunityPost::class,
            'post', 'timeline', Post::class => Post::class,
            default => throw new RuntimeException('Unsupported gift target.'),
        };
    }

    public function resolveGiftable(string $giftableType, string $giftableId): Model
    {
        $modelClass = $this->resolveGiftableClass($giftableType);

        return $modelClass::query()->with('user')->findOrFail($giftableId);
    }

    protected function assertRecipientCanReceiveGifts(User $recipient): void
    {
        if (! canReceiveGifts($recipient)) {
            throw new RuntimeException('This creator cannot receive gifts.');
        }
    }

    public function ensureUserWallet(User $user): Wallet
    {
        $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();

        if ($wallet) {
            return $wallet;
        }

        return Wallet::create([
            'user_id' => $user->id,
            'balance' => 0,
            'promoter_balance' => 0,
            'referral_balance' => 0,
            'currency' => strtoupper((string) (userBaseCurrency($user->id) ?? 'USD')),
            'level' => userLevel($user->id),
            'paykoin_spendable' => 0,
            'paykoin_earned' => 0,
        ]);
    }

    public function formatGiftForUi(PostGift $gift): array
    {
        return [
            'id' => $gift->id,
            'emoji' => $gift->meta['emoji'] ?? '🎁',
            'name' => $gift->meta['name'] ?? 'Gift',
            'price' => (int) $gift->pk_amount,
            'sender' => $gift->sender?->username ?? 'member',
        ];
    }
}
