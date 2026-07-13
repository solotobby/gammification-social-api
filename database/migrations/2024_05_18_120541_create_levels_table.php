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
        Schema::create('levels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('amount');
            $table->string('reg_bonus');
            $table->string('ref_bonus');
            $table->string('min_withdrawal');
            $table->string('earning_per_view');
            $table->string('earning_per_like')->nullable();
            $table->string('earning_per_comment')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('levels');
    }
};
