<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_logins', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('status');
            $table->timestamp('used_at')->nullable()->after('expires_at');
            $table->string('user_agent_hash', 64)->nullable()->after('used_at');
        });
    }

    public function down(): void
    {
        Schema::table('admin_logins', function (Blueprint $table) {
            $table->dropColumn(['expires_at', 'used_at', 'user_agent_hash']);
        });
    }
};
