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
        Schema::create('follows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('follower_id');
            $table->uuid('following_id');

            $table->foreign('follower_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->foreign('following_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->unique(['follower_id', 'following_id']);

            $table->timestamps();

            // Performance indexes
            $table->index('follower_id');
            $table->index('following_id');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('follows');
    }
};
