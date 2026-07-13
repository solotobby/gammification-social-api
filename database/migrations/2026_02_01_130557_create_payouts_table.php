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
        Schema::create('payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('engagement_monthly_stats_id')->index();
            $table->uuid('user_id')->constrained('users')->cascadeOnDelete()->index();
            $table->enum('level', ['Creator', 'Influencer', 'Basic'])->index();
            $table->decimal('amount', 6, 2);
            $table->string('total_engagement');
            $table->string('month');
            $table->string('currency');
            $table->enum('status', ['Queued', 'Paid', 'Rejected'])->default('Queued')->index();
            $table->enum('type', ['Freemium', 'Premium'])->default('Premium')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
