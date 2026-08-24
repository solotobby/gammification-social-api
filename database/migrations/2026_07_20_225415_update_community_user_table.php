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
        Schema::table('community_users', function (Blueprint $table) {
             $table->enum('status', ['active', 'banned'])
                ->default('active')
                ->after('role')
                ->comment('Banned members keep their pivot row (for history/unban) '
                    . 'but are excluded from active member counts, is_member checks, '
                    . 'and the feed.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('community_users', function (Blueprint $table) {
             $table->dropColumn('status');
        });
    }
};
