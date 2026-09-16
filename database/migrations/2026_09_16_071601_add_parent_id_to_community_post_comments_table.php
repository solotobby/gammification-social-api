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
        Schema::table('community_post_comments', function (Blueprint $table) {
            if (! Schema::hasColumn('community_post_comments', 'parent_id')) {
                $table->uuid('parent_id')->nullable()->after('community_post_id')->index();
                $table->foreign('parent_id')
                    ->references('id')
                    ->on('community_post_comments')
                    ->cascadeOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('community_post_comments', function (Blueprint $table) {
            if (Schema::hasColumn('community_post_comments', 'parent_id')) {
                $table->dropForeign(['parent_id']);
                $table->dropColumn('parent_id');
            }
        });
    }
};
