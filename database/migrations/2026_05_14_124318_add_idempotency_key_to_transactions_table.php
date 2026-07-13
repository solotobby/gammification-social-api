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
        Schema::table('transactions', function (Blueprint $table) {
            $table->uuid('idempotency_key')
                ->nullable()
                ->unique()
                ->after('ref');
            $table->string('provider')->default('kora')->after('idempotency_key');
            $table->timestamp('paid_at')->nullable()->after('customer');
            $table->index('idempotency_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['idempotency_key', 'provider']);
        });
    }
};
