<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paykoin_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('type'); // topup, convert, gift_sent, gift_received, admin_credit
            $table->bigInteger('pk_amount'); // signed: + credit, - debit
            $table->decimal('fiat_amount', 14, 2)->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('ref')->nullable();
            $table->string('description')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paykoin_transactions');
    }
};
