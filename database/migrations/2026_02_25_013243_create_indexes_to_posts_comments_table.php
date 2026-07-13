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

        // 1️⃣ posts table
        Schema::table('posts', function (Blueprint $table) {
            $table->index('created_at', 'posts_created_at_index');
            $table->index(['created_at', 'likes', 'comments', 'comment_external', 'views_external', 'has_video', 'has_images'], 'posts_trending_index');
            $table->fullText('content', 'posts_content_fulltext'); // MySQL FULLTEXT
        });

        // 2️⃣ trending_topics table
        Schema::table('trending_topics', function (Blueprint $table) {
            $table->index('score', 'trending_score_index');
        });

        if (Schema::hasTable('user_likes')) {
            Schema::table('user_likes', function (Blueprint $table) {
                $table->index(['post_id', 'user_id', 'poster_user_id', 'type'], 'likes_post_id_index');
            });
        }

        // 4️⃣ Optional: comments table
        if (Schema::hasTable('user_comments')) {
            Schema::table('user_comments', function (Blueprint $table) {
                $table->index(['post_id', 'user_id', 'poster_user_id', 'type'], 'comments_post_id_index');
            });
        }
        if (Schema::hasTable('user_views')) {
            Schema::table('user_views', function (Blueprint $table) {
                $table->index(['post_id', 'user_id', 'poster_user_id', 'type'], 'views_post_id_index');
            });
        }
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Schema::dropIfExists('indexes_to_posts_comments');
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex('posts_created_at_index');
            $table->dropIndex('posts_trending_index');
            $table->dropFullText('posts_content_fulltext');
        });

        Schema::table('trending_topics', function (Blueprint $table) {
            $table->dropIndex('trending_score_index');
        });

        if (Schema::hasTable('user_likes')) {
            Schema::table('user_likes', function (Blueprint $table) {
                $table->dropIndex('likes_post_id_index');
            });
        }

        if (Schema::hasTable('user_comments')) {
            Schema::table('user_comments', function (Blueprint $table) {
                $table->dropIndex('comments_post_id_index');
            });
        }
        if (Schema::hasTable('user_views')) {
            Schema::table('user_views', function (Blueprint $table) {
                $table->dropIndex('views_post_id_index');
            });
        }
    }
};
