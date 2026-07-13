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
        Schema::create('hashtag_trends', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('hashtag_id');
 
            $table->unsignedInteger('score')
                ->default(0);
            
            $table->string('time')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hashtag_trends');
    }
};
