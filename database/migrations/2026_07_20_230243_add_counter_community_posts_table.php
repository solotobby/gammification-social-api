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
        Schema::table('community_posts', function (Blueprint $table) {
             $table->unsignedInteger('views_count')->default(0)->after('content');
            $table->unsignedInteger('likes_count')->default(0)->after('views_count');
            $table->unsignedInteger('comments_count')->default(0)->after('likes_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('community_posts', function (Blueprint $table) {
            $table->dropColumn(['views_count', 'likes_count', 'comments_count']);
        });
    }
};
