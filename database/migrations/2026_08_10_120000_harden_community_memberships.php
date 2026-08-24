<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communities', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('user_id');
        });

        // Remove duplicate pivot rows before adding the unique index.
        $dupes = DB::table('community_users')
            ->select('community_id', 'user_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('community_id', 'user_id')
            ->having('cnt', '>', 1)
            ->get();

        foreach ($dupes as $dupe) {
            $rows = DB::table('community_users')
                ->where('community_id', $dupe->community_id)
                ->where('user_id', $dupe->user_id)
                ->orderBy('created_at')
                ->get();

            $keepId = $rows->first()->id;

            DB::table('community_users')
                ->where('community_id', $dupe->community_id)
                ->where('user_id', $dupe->user_id)
                ->where('id', '!=', $keepId)
                ->delete();
        }

        Schema::table('community_users', function (Blueprint $table) {
            $table->unique(['community_id', 'user_id'], 'community_users_community_user_unique');
        });
    }

    public function down(): void
    {
        Schema::table('community_users', function (Blueprint $table) {
            $table->dropUnique('community_users_community_user_unique');
        });

        Schema::table('communities', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
