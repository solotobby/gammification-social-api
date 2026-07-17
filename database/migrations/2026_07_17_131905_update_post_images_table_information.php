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
        Schema::table('post_images', function (Blueprint $table) {
            // path = medium variant, thumbnail_path = thumb variant (both already exist)
            $table->string('full_path')->nullable()->after('thumbnail_path');
            $table->string('medium_path')->nullable()->after('full_path');
            $table->unsignedInteger('width')->nullable()->after('medium_path');
            $table->unsignedInteger('height')->nullable()->after('width');
            $table->unsignedInteger('size_bytes')->nullable()->after('height');
            $table->text('failure_reason')->nullable()->after('processing_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('post_images', function (Blueprint $table) {
            $table->dropColumn(['full_path', 'width', 'height', 'size_bytes', 'failure_reason']);
        });
    }
};
