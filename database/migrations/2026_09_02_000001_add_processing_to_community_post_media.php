<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('community_post_media', function (Blueprint $table) {
            $table->enum('processing_status', ['processing', 'completed', 'failed'])
                ->default('completed')
                ->after('type');
            $table->string('thumbnail_path')->nullable()->after('path');
            $table->unsignedInteger('width')->nullable()->after('sort');
            $table->unsignedInteger('height')->nullable()->after('width');
            $table->unsignedBigInteger('size_bytes')->nullable()->after('height');
            $table->text('failure_reason')->nullable()->after('size_bytes');
        });

        Schema::table('community_posts', function (Blueprint $table) {
            $table->string('media_status')->default('ready')->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('community_post_media', function (Blueprint $table) {
            $table->dropColumn([
                'processing_status',
                'thumbnail_path',
                'width',
                'height',
                'size_bytes',
                'failure_reason',
            ]);
        });

        Schema::table('community_posts', function (Blueprint $table) {
            $table->dropColumn('media_status');
        });
    }
};
