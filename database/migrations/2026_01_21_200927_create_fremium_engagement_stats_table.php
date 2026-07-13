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
        Schema::create('fremium_engagement_stats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->constrained('users')->cascadeOnDelete()->index();
            $table->enum('level', ['Creator', 'Influencer', 'Basic'])->index();

            $table->date('date')->index();

            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('comments')->default(0);

            $table->unsignedBigInteger('points')->default(0);

            $table->timestamps();

            $table->unique(['user_id', 'level', 'date'], 'fremium_engagement_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fremium_engagement_stats');
    }
};
