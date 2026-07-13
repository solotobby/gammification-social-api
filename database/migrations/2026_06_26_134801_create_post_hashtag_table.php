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
        Schema::create('post_hashtag', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('post_id');
                // ->constrained()
                // ->cascadeOnDelete();

            $table->uuid('hashtag_id');
                // ->constrained()
                // ->cascadeOnDelete();

            $table->unique([
                'post_id',
                'hashtag_id'
            ]);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('post_hashtag');
    }
};
