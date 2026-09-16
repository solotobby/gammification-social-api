<?php

namespace Tests\Feature;

use App\Models\Payout;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserPayoutsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'username' => 'creator_alice',
            'name' => 'Alice Creator',
        ]);

        $this->otherUser = User::factory()->create([
            'username' => 'creator_bob',
            'name' => 'Bob Creator',
        ]);

        Wallet::create([
            'user_id' => $this->user->id,
            'currency' => 'NGN',
            'balance' => 0,
            'promoter_balance' => 0,
            'referral_balance' => 0,
            'paykoin_spendable' => 100,
            'paykoin_earned' => 0,
        ]);
    }

    public function test_user_cannot_access_payouts_unauthenticated(): void
    {
        $response = $this->getJson('/v1/user/payouts');

        $response->assertStatus(401);
    }

    public function test_user_payouts_list_shows_only_queued_and_paid(): void
    {
        // Queued payout for user
        $queued = Payout::create([
            'user_id' => $this->user->id,
            'engagement_monthly_stats_id' => (string) Str::uuid(),
            'level' => 'Creator',
            'month' => '2026-08',
            'amount' => 500.00,
            'total_engagement' => '15000',
            'currency' => 'NGN',
            'status' => 'Queued',
            'type' => 'Premium',
        ]);

        // Paid payout for user
        $paid = Payout::create([
            'user_id' => $this->user->id,
            'engagement_monthly_stats_id' => (string) Str::uuid(),
            'level' => 'Creator',
            'month' => '2026-07',
            'amount' => 1200.00,
            'total_engagement' => '30000',
            'currency' => 'NGN',
            'status' => 'Paid',
            'type' => 'Premium',
        ]);

        // Rejected payout for user (MUST BE EXCLUDED)
        Payout::create([
            'user_id' => $this->user->id,
            'engagement_monthly_stats_id' => (string) Str::uuid(),
            'level' => 'Creator',
            'month' => '2026-06',
            'amount' => 800.00,
            'total_engagement' => '20000',
            'currency' => 'NGN',
            'status' => 'Rejected',
            'type' => 'Premium',
        ]);

        // Paid payout for another user (MUST BE EXCLUDED)
        Payout::create([
            'user_id' => $this->otherUser->id,
            'engagement_monthly_stats_id' => (string) Str::uuid(),
            'level' => 'Creator',
            'month' => '2026-07',
            'amount' => 999.00,
            'total_engagement' => '10000',
            'currency' => 'NGN',
            'status' => 'Paid',
            'type' => 'Premium',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/v1/user/payouts');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'summary' => [
                        'total_paid' => 1200.00,
                        'total_queued' => 500.00,
                        'currency' => 'NGN',
                    ],
                ],
            ]);

        $payoutsData = $response->json('data.payouts.data');
        $this->assertCount(2, $payoutsData);

        $statuses = collect($payoutsData)->pluck('status')->all();
        $this->assertContains('Queued', $statuses);
        $this->assertContains('Paid', $statuses);
        $this->assertNotContains('Rejected', $statuses);
    }

    public function test_user_can_filter_payouts_by_status(): void
    {
        Payout::create([
            'user_id' => $this->user->id,
            'engagement_monthly_stats_id' => (string) Str::uuid(),
            'level' => 'Creator',
            'month' => '2026-08',
            'amount' => 450.00,
            'total_engagement' => '10000',
            'currency' => 'NGN',
            'status' => 'Queued',
            'type' => 'Premium',
        ]);

        Payout::create([
            'user_id' => $this->user->id,
            'engagement_monthly_stats_id' => (string) Str::uuid(),
            'level' => 'Creator',
            'month' => '2026-07',
            'amount' => 750.00,
            'total_engagement' => '20000',
            'currency' => 'NGN',
            'status' => 'Paid',
            'type' => 'Premium',
        ]);

        // Filter for Paid
        $paidResponse = $this->actingAs($this->user, 'api')
            ->getJson('/v1/user/payouts?status=Paid');

        $paidResponse->assertStatus(200);
        $paidItems = $paidResponse->json('data.payouts.data');
        $this->assertCount(1, $paidItems);
        $this->assertEquals('Paid', $paidItems[0]['status']);

        // Filter for Queued
        $queuedResponse = $this->actingAs($this->user, 'api')
            ->getJson('/v1/user/payouts?status=Queued');

        $queuedResponse->assertStatus(200);
        $queuedItems = $queuedResponse->json('data.payouts.data');
        $this->assertCount(1, $queuedItems);
        $this->assertEquals('Queued', $queuedItems[0]['status']);
    }
}
