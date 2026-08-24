<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dedupeTable('user_likes');
        $this->dedupeTable('user_views');
        $this->dedupeTable('user_comments');

        if (! $this->indexExists('user_likes', 'user_likes_user_post_unique')) {
            Schema::table('user_likes', function (Blueprint $table) {
                $table->unique(['user_id', 'post_id'], 'user_likes_user_post_unique');
            });
        }

        if (! $this->indexExists('user_views', 'user_views_user_post_unique')) {
            Schema::table('user_views', function (Blueprint $table) {
                $table->unique(['user_id', 'post_id'], 'user_views_user_post_unique');
            });
        }

        if (! $this->indexExists('user_comments', 'user_comments_user_post_unique')) {
            Schema::table('user_comments', function (Blueprint $table) {
                $table->unique(['user_id', 'post_id'], 'user_comments_user_post_unique');
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('user_likes', 'user_likes_user_post_unique')) {
            Schema::table('user_likes', function (Blueprint $table) {
                $table->dropUnique('user_likes_user_post_unique');
            });
        }

        if ($this->indexExists('user_views', 'user_views_user_post_unique')) {
            Schema::table('user_views', function (Blueprint $table) {
                $table->dropUnique('user_views_user_post_unique');
            });
        }

        if ($this->indexExists('user_comments', 'user_comments_user_post_unique')) {
            Schema::table('user_comments', function (Blueprint $table) {
                $table->dropUnique('user_comments_user_post_unique');
            });
        }
    }

    private function dedupeTable(string $table): void
    {
        DB::statement("
            DELETE t1 FROM {$table} t1
            INNER JOIN {$table} t2
                ON t1.user_id = t2.user_id
                AND t1.post_id = t2.post_id
                AND t1.id > t2.id
        ");
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $rows = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);

        return count($rows) > 0;
    }
};
