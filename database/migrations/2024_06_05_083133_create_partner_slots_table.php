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
        Schema::create('partner_slots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('partner_id');
            $table->bigInteger('beginner');
            $table->bigInteger('creator');
            $table->bigInteger('influencer');
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('partner_slots');
    }
};
