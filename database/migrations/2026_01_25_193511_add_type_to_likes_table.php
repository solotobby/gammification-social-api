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
            $table->enum('type', ['like', 'self-like'])->default('like')->after('poster_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_likes', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
