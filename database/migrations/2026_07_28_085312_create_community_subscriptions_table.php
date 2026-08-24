<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('community_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('community_id')->constrained('communities')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('billing_type');                 // one_off | subscription
            $table->string('billing_interval')->nullable();  // monthly | yearly | ... (null for one_off)
            $table->string('fee_payer');                     // creator | members

            $table->decimal('amount', 12, 2);                // what the member is charged
            $table->decimal('platform_fee', 12, 2);
            $table->decimal('creator_amount', 12, 2);        // what the creator receives

            $table->string('status')->default('pending');    // pending | active | cancelled | expired | failed

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();      // always null for one_off (permanent access)
            $table->timestamp('cancelled_at')->nullable();

            // ---- payment gateway placeholders — fill in when you integrate ----
            $table->string('gateway')->nullable();
            $table->string('gateway_reference')->nullable()->unique();
            $table->json('gateway_meta')->nullable();

            $table->timestamps();

            $table->index(['community_id', 'user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('community_subscriptions');
    }
};
