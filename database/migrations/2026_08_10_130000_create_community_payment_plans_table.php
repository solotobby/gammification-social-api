<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_payment_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('community_id')->constrained('communities')->cascadeOnDelete();
            $table->string('currency', 3);
            $table->string('billing_interval');
            $table->decimal('amount', 12, 2);
            $table->string('flutterwave_plan_id');
            $table->string('flutterwave_plan_token')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(
                ['community_id', 'currency', 'billing_interval'],
                'community_payment_plans_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_payment_plans');
    }
};
