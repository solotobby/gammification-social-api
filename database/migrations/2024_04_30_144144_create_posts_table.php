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
        Schema::create('posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('unicode');
            $table->longText('content');
            $table->bigInteger('views')->default(0);
            $table->bigInteger('views_external')->default(0);
            $table->bigInteger('clicks')->default(0);
            $table->bigInteger('likes')->default(0);
            $table->bigInteger('likes_external')->default(0);
            $table->bigInteger('comments')->default(0);
            $table->string('status')->default('LIVE');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
