<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->unsignedBigInteger('paykoin_spendable')->default(0)->after('referral_balance');
            $table->unsignedBigInteger('paykoin_earned')->default(0)->after('paykoin_spendable');
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn(['paykoin_spendable', 'paykoin_earned']);
        });
    }
};
