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
              $table->enum('fee_payer', ['creator', 'members'])
                ->default('creator')
                ->after('monthly_fee')
                ->comment('Who absorbs the platform commission on a paid community: '
                    . 'the creator (deducted from their payout) or members (added on '
                    . 'top of the price they pay to join).');
 
            $table->unsignedTinyInteger('platform_fee_percent')
                ->nullable()
                ->after('fee_payer')
                ->comment('Snapshot of the platform fee percentage at the time this '
                    . 'community was created, so payouts stay consistent even if the '
                    . 'global rate changes later.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('communities', function (Blueprint $table) {
             $table->dropColumn(['fee_payer', 'platform_fee_percent']);
        });
    }
};
