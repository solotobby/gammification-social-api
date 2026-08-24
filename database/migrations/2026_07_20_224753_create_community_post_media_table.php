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
        Schema::create('community_post_media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('community_post_id')->constrained('community_posts')->cascadeOnDelete();
            $table->string('path');
            $table->enum('type', ['image', 'video'])->default('image');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('community_post_media');
    }
};
