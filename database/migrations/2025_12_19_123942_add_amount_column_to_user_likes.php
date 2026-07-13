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
        Schema::table('user_likes', function (Blueprint $table) {
            $table->decimal('amount', 10, 5)->default(0)->after('is_paid');
            $table->uuid('poster_user_id')->nullable()->after('post_id');
            $table->index('poster_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_likes', function (Blueprint $table) {
            $table->dropIndex(['poster_user_id']);
            $table->dropColumn('amount');
            $table->dropColumn('poster_user_id');
        });
    }
};
