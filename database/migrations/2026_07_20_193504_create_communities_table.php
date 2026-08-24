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
        Schema::create('communities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->uuid('community_categories_id');
            $table->text('description')->nullable();
            $table->enum('type', ['public', 'private', 'paid', 'approval'])->default('public');
            $table->decimal('monthly_fee', 10, 2)->default(0);
            $table->string('image')->nullable();
            $table->string('banner')->nullable();
            $table->uuid('user_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communities');
    }
};
