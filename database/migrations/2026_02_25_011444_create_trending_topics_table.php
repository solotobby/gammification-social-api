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
        Schema::create('trending_topics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('phrase')->unique();
            $table->string('slug')->unique();
            $table->float('score')->default(0);
            $table->string('timeframe')->default('6h'); // e.g.
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trending_topics');
    }
};
