<?php

namespace Tests\Feature;

use App\Models\Community;
use App\Models\CommunityCategory;
use App\Models\CommunityPost;
use App\Models\PaykoinTransaction;
use App\Models\PostGift;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PayKoinAndGiftTest extends TestCase
{
    use RefreshDatabase;

    protected User $sender;

    protected User $creator;

    protected Community $community;

    protected CommunityPost $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sender = User::factory()->create([
            'username' => 'sender_user',
            'name' => 'Sender User',
        ]);

        $this->creator = User::factory()->create([
            'username' => 'creator_user',
            'name' => 'Creator User',
        ]);

        Wallet::create([
            'user_id' => $this->sender->id,
            'currency' => 'NGN',
            'balance' => 0,
            'promoter_balance' => 0,
            'referral_balance' => 0,
            'paykoin_spendable' => 100,
            'paykoin_earned' => 0,
        ]);

        Wallet::create([
            'user_id' => $this->creator->id,
            'currency' => 'NGN',
            'balance' => 0,
            'promoter_balance' => 0,
            'referral_balance' => 0,
            'paykoin_spendable' => 0,
            'paykoin_earned' => 0,
        ]);

        $category = CommunityCategory::create([
            'name' => 'Tech & Gaming',
            'slug' => 'tech-gaming',
            'icon' => 'tech-icon',
        ]);

        $this->community = Community::create([
            'user_id' => $this->creator->id,
            'community_categories_id' => $category->id,
            'name' => 'Creator Space',
            'slug' => 'creator-space',
            'type' => 'public',
            'status' => 'active',
        ]);

        $this->post = CommunityPost::create([
            'community_id' => $this->community->id,
            'user_id' => $this->creator->id,
            'content' => 'Hello from creator!',
        ]);
    }

    public function test_can_get_gift_catalog_publicly(): void
    {
        $response = $this->getJson('/v1/gifts');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Gift catalog retrieved successfully',
            ])
            ->assertJsonStructure([
                'data' => [
                    'gifts' => [
                        '*' => ['id', 'name', 'emoji', 'price', 'tier'],
                    ],
                    'by_tier',
                    'spendable',
                ],
            ]);

        $this->assertCount(20, $response->json('data.gifts'));
    }

    public function test_can_get_paykoin_rates(): void
    {
        $response = $this->getJson('/v1/paykoin/rates?currency=NGN');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'currency' => 'NGN',
                    'rates' => [
                        'list' => 10,
                        'convert' => 7.5,
                    ],
                    'min_top_up' => 100,
                ],
            ]);
    }

    public function test_can_get_authenticated_paykoin_balance(): void
    {
        $response = $this->actingAs($this->sender, 'api')
            ->getJson('/v1/paykoin/balance');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'paykoin_spendable' => 100,
                    'paykoin_earned' => 0,
                    'currency' => 'NGN',
                ],
            ]);
    }

    public function test_can_initiate_paykoin_topup(): void
    {
        Http::fake([
            'https://api.korapay.com/merchant/api/v1/charges/initialize' => Http::response([
                'status' => true,
                'data' => [
                    'checkout_url' => 'https://checkout.korapay.com/pay/test1234',
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->sender, 'api')
            ->postJson('/v1/paykoin/topup', [
                'amount' => 1000,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'checkout_url' => 'https://checkout.korapay.com/pay/test1234',
                ],
            ]);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $this->sender->id,
            'type' => 'paykoin_topup',
            'amount' => 1000,
            'status' => 'initiated',
        ]);
    }

    public function test_can_credit_paykoin_via_korapay_webhook(): void
    {
        $ref = 'PKN-20260906-9999';

        $tx = Transaction::create([
            'user_id' => $this->sender->id,
            'idempotency_key' => 'idemp-123',
            'provider' => 'kora',
            'ref' => $ref,
            'amount' => 1000,
            'currency' => 'NGN',
            'status' => 'initiated',
            'type' => 'paykoin_topup',
            'action' => 'Debit',
            'description' => 'PayKoin top-up',
            'meta' => [
                'pk_amount' => 100,
                'charge_amount_ngn' => 1000,
            ],
        ]);

        $secretKey = 'test_kora_secret';
        config(['services.env.kora_sec' => $secretKey]);

        $payloadData = [
            'reference' => $ref,
            'status' => 'success',
            'amount' => 1000,
            'currency' => 'NGN',
        ];

        $payload = [
            'event' => 'charge.success',
            'data' => $payloadData,
        ];

        $signature = hash_hmac('sha256', json_encode($payloadData), $secretKey);

        $response = $this->withHeaders([
            'x-korapay-signature' => $signature,
        ])->postJson('/v1/webhooks/korapay', $payload);

        $response->assertStatus(200);

        $this->sender->refresh();
        $this->assertEquals(200, $this->sender->wallet->paykoin_spendable);

        $this->assertDatabaseHas('paykoin_transactions', [
            'user_id' => $this->sender->id,
            'type' => 'topup',
            'pk_amount' => 100,
            'ref' => $ref,
        ]);

        $this->assertEquals('successful', $tx->fresh()->status);
    }

    public function test_can_send_gift_on_community_post(): void
    {
        $response = $this->actingAs($this->sender, 'api')
            ->postJson('/v1/gifts/send', [
                'artifact_id' => 'rose', // 5 PK
                'post_id' => $this->post->id,
                'post_type' => 'community_post',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Gift sent successfully',
            ]);

        $this->sender->refresh();
        $this->creator->refresh();
        $this->post->refresh();

        // 100 initial spendable - 5 = 95
        $this->assertEquals(95, $this->sender->wallet->paykoin_spendable);
        // 0 initial earned + 5 = 5
        $this->assertEquals(5, $this->creator->wallet->paykoin_earned);
        // post gifts_count incremented
        $this->assertEquals(1, $this->post->gifts_count);

        $this->assertDatabaseHas('post_gifts', [
            'sender_id' => $this->sender->id,
            'recipient_id' => $this->creator->id,
            'artifact_id' => 'rose',
            'pk_amount' => 5,
        ]);

        $this->assertDatabaseHas('paykoin_transactions', [
            'user_id' => $this->sender->id,
            'type' => 'gift_sent',
            'pk_amount' => -5,
        ]);

        $this->assertDatabaseHas('paykoin_transactions', [
            'user_id' => $this->creator->id,
            'type' => 'gift_received',
            'pk_amount' => 5,
        ]);
    }

    public function test_cannot_gift_own_post(): void
    {
        $response = $this->actingAs($this->creator, 'api')
            ->postJson('/v1/gifts/send', [
                'artifact_id' => 'rose',
                'post_id' => $this->post->id,
                'post_type' => 'community_post',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'You cannot gift your own post.',
            ]);
    }

    public function test_cannot_gift_with_insufficient_balance(): void
    {
        $this->sender->wallet->update(['paykoin_spendable' => 2]);

        $response = $this->actingAs($this->sender, 'api')
            ->postJson('/v1/gifts/send', [
                'artifact_id' => 'rose', // 5 PK
                'post_id' => $this->post->id,
                'post_type' => 'community_post',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Not enough PayKoin to send this gift.',
            ]);
    }

    public function test_can_convert_earned_paykoin_to_fiat(): void
    {
        $this->creator->wallet->update([
            'paykoin_earned' => 100,
            'balance' => 500,
        ]);

        $response = $this->actingAs($this->creator, 'api')
            ->postJson('/v1/paykoin/convert', [
                'amount' => 100,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'PayKoin converted to wallet cash successfully',
                'data' => [
                    'paykoin_earned' => 0,
                    // 500 + (100 * 7.50) = 1250
                    'wallet_balance' => 1250,
                ],
            ]);

        $this->creator->refresh();
        $this->assertEquals(0, $this->creator->wallet->paykoin_earned);
        $this->assertEquals(1250, (float) $this->creator->wallet->balance);

        $this->assertDatabaseHas('paykoin_transactions', [
            'user_id' => $this->creator->id,
            'type' => 'convert',
            'pk_amount' => -100,
            'fiat_amount' => 750,
        ]);
    }

    public function test_can_list_paykoin_transactions(): void
    {
        PaykoinTransaction::create([
            'user_id' => $this->sender->id,
            'type' => 'topup',
            'pk_amount' => 100,
            'fiat_amount' => 1000,
            'currency' => 'NGN',
            'ref' => 'PKN-123456',
            'description' => 'PayKoin top-up',
        ]);

        $response = $this->actingAs($this->sender, 'api')
            ->getJson('/v1/paykoin/transactions');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Transactions retrieved successfully',
            ])
            ->assertJsonPath('data.data.0.type', 'topup')
            ->assertJsonPath('data.data.0.pk_amount', 100);
    }

    public function test_can_get_gifts_for_a_post(): void
    {
        PostGift::create([
            'sender_id' => $this->sender->id,
            'recipient_id' => $this->creator->id,
            'giftable_type' => CommunityPost::class,
            'giftable_id' => $this->post->id,
            'artifact_id' => 'rose',
            'pk_amount' => 5,
            'ref' => 'PKN-GIFT-1',
            'meta' => [
                'name' => 'Rose',
                'emoji' => '🌹',
                'tier' => 'classic',
            ],
        ]);

        $response = $this->getJson("/v1/gifts/post/community_post/{$this->post->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'total' => 1,
                    'recent' => [
                        [
                            'name' => 'Rose',
                            'emoji' => '🌹',
                            'price' => 5,
                            'sender' => 'sender_user',
                        ],
                    ],
                ],
            ]);
    }
}
