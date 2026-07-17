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
        Schema::table('post_videos', function (Blueprint $table) {
            // path = SD/default WebM (always generated), thumbnail_path = poster (both already exist)
            $table->string('hd_path')->nullable()->after('path'); // Influencer-only, higher bitrate
            $table->text('failure_reason')->nullable()->after('processing_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('post_videos', function (Blueprint $table) {
            $table->dropColumn(['hd_path', 'failure_reason']);
        });
    }
};
