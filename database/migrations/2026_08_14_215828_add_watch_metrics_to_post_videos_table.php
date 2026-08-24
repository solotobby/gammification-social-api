<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_videos', function (Blueprint $table) {
            if (! Schema::hasColumn('post_videos', 'total_watch_time')) {
                $table->decimal('total_watch_time', 12, 2)->default(0)->after('avg_watch_time');
            }
            if (! Schema::hasColumn('post_videos', 'watch_sessions')) {
                $table->unsignedInteger('watch_sessions')->default(0)->after('total_watch_time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('post_videos', function (Blueprint $table) {
            if (Schema::hasColumn('post_videos', 'watch_sessions')) {
                $table->dropColumn('watch_sessions');
            }
            if (Schema::hasColumn('post_videos', 'total_watch_time')) {
                $table->dropColumn('total_watch_time');
            }
        });
    }
};
