<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('community_id')->constrained('communities')->cascadeOnDelete();
            $table->foreignUuid('community_subscription_id')->nullable()->constrained('community_subscriptions')->nullOnDelete();
            $table->foreignUuid('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignUuid('payer_user_id')->constrained('users')->cascadeOnDelete();

            $table->decimal('gross_amount', 12, 2);
            $table->decimal('platform_fee', 12, 2);
            $table->decimal('creator_amount', 12, 2);
            $table->string('currency', 10)->default('NGN');
            $table->string('billing_type');
            $table->string('billing_interval')->nullable();
            $table->enum('status', ['completed', 'refunded'])->default('completed');
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->index(['community_id', 'paid_at']);
            $table->unique('community_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_payouts');
    }
};
