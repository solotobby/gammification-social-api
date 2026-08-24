<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('community_post_comments', 'uuid')) {
            return;
        }

        // Migration originally created the PK column as "uuid" — the models
        // and UuidTrait all expect "id", same as every other table.
        DB::statement('ALTER TABLE community_post_comments CHANGE uuid id CHAR(36) NOT NULL');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('community_post_comments', 'id')) {
            return;
        }

        DB::statement('ALTER TABLE community_post_comments CHANGE id uuid CHAR(36) NOT NULL');
    }
};
