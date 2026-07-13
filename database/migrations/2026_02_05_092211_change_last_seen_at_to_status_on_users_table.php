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
        Schema::table('users', function (Blueprint $table) {
            // Drop last_seen_at
            if (Schema::hasColumn('users', 'last_seen_at')) {
                $table->dropColumn('last_seen_at');
            }

            // Add status enum
            $table->enum('status', [
                'ACTIVE',
                'SHADOW_BANNED',
                'BLOCKED'
            ])->default('ACTIVE')->after('email_verified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Revert status
            if (Schema::hasColumn('users', 'status')) {
                $table->dropColumn('status');
            }

            // Restore last_seen_at
            $table->timestamp('last_seen_at')->nullable()->after('email_verified_at');
        });
    }
};
