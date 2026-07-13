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
        Schema::create('engagement_monthly_stats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->constrained('users')->cascadeOnDelete()->index();

            $table->enum('level', ['Creator', 'Influencer'])->index();

            // Format: YYYY-MM (e.g. 2026-01)
            $table->char('month', 7)->index();

            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
            $table->unsignedBigInteger('points')->default(0);

            $table->timestamps();

            // Prevent duplicate monthly records per user per tier
            $table->unique(['user_id', 'level', 'month'], 'unique_user_level_month');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('engagement_monthly_stats');
    }
};
