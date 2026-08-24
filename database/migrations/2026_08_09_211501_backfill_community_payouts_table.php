<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('community_payouts') || ! Schema::hasTable('community_subscriptions')) {
            return;
        }

        $subscriptions = DB::table('community_subscriptions')
            ->where('status', 'active')
            ->get();

        foreach ($subscriptions as $sub) {
            $exists = DB::table('community_payouts')
                ->where('community_subscription_id', $sub->id)
                ->exists();

            if ($exists) {
                continue;
            }

            $community = DB::table('communities')->where('id', $sub->community_id)->first();

            DB::table('community_payouts')->insert([
                'id' => (string) Str::uuid(),
                'community_id' => $sub->community_id,
                'community_subscription_id' => $sub->id,
                'transaction_id' => null,
                'payer_user_id' => $sub->user_id,
                'gross_amount' => $sub->amount,
                'platform_fee' => $sub->platform_fee,
                'creator_amount' => $sub->creator_amount,
                'currency' => strtoupper((string) ($community->currency ?? 'NGN')),
                'billing_type' => $sub->billing_type,
                'billing_interval' => $sub->billing_interval,
                'status' => 'completed',
                'paid_at' => $sub->starts_at ?? $sub->created_at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Non-destructive backfill — leave rows in place on rollback.
    }
};
