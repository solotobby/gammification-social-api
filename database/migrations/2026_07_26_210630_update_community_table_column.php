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
        Schema::table('communities', function (Blueprint $table) {
            $table->enum('billing_type', ['one_off', 'subscription'])
                ->nullable()
                ->after('fee_payer')
                ->comment('Only meaningful when type=paid. One-off: single one-time '
                    . 'payment to join. Subscription: recurring charge on '
                    . 'billing_interval.');

            $table->enum('billing_interval', ['weekly', 'monthly', 'quarterly', 'biannual', 'annual'])
                ->nullable()
                ->after('billing_type')
                ->comment('Only set when billing_type=subscription.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('communities', function (Blueprint $table) {
            $table->dropColumn(['billing_type', 'billing_interval']);
        });
    }
};
