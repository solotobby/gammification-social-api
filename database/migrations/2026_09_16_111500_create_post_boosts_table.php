<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('post_boosts')) {
            Schema::create('post_boosts', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('post_id');
                $table->uuid('user_id');
                $table->text('target_url');
                $table->string('cta', 64)->default('Shop Now');
                $table->unsignedInteger('total_clicks');
                $table->unsignedInteger('delivered_clicks')->default(0);
                $table->unsignedInteger('remaining_clicks');
                $table->unsignedInteger('pk_cost');
                $table->unsignedInteger('rate_pk')->default(3);
                $table->boolean('platform_payhankey')->default(true);
                $table->boolean('platform_partner')->default(true);
                $table->string('status', 32)->default('active'); // active, paused, completed, cancelled
                $table->string('ref', 64)->nullable();
                $table->timestamps();

                $table->index(['post_id', 'status']);
                $table->index(['user_id', 'status']);
                $table->index('status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('post_boosts');
    }
};
