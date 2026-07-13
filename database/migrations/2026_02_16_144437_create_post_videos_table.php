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
        Schema::create('post_videos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable()->index(); // null = official blog
            $table->uuid('post_id')->nullable()->index();

            $table->string('path'); // Main video URL
            $table->string('thumbnail_path')->nullable();
            $table->string('public_id')->nullable(); // Cloudinary public ID

            // Video metadata
            $table->integer('duration')->nullable(); // Duration in seconds
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->string('format')->nullable();
            $table->bigInteger('file_size')->nullable(); // Size in bytes

            // Processing status
            $table->enum('processing_status', ['processing', 'completed', 'failed'])->default('processing');

            // Quality versions (URLs for different qualities)
            $table->text('quality_versions')->nullable(); // JSON: {high: url, medium: url, low: url}

            // Analytics
            $table->integer('view_count')->default(0);
            $table->integer('play_count')->default(0);
            $table->decimal('avg_watch_time', 5, 2)->nullable(); // 



            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('post_id')->references('id')->on('posts')->onDelete('cascade');
            $table->index('processing_status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('post_videos');
    }
};
