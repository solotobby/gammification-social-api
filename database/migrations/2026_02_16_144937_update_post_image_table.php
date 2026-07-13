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
        // Update posts table
        Schema::table('posts', function (Blueprint $table) {
            $table->boolean('has_video')->default(false)->after('status');
            $table->boolean('has_images')->default(false)->after('has_video');
        });

        // Update post_images table
        Schema::table('post_images', function (Blueprint $table) {
            $table->string('thumbnail_path')->nullable()->after('path');
            $table->enum('processing_status', ['processing', 'completed', 'failed'])->default('completed')->after('thumbnail_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['has_video', 'has_images']);
        });

        Schema::table('post_images', function (Blueprint $table) {
            $table->dropColumn(['thumbnail_path', 'processing_status']);
        });
    }
};
