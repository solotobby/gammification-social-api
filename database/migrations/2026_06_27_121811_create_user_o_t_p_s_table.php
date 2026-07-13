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
        Schema::create('user_o_t_p_s', function (Blueprint $table) {
           $table->uuid('id')->primary();

            $table->uuid('user_id');

            $table->string('otp');

            $table->timestamp('expires_at');

            $table->boolean('is_used')->default(false);

            $table->timestamps();

            $table->index(['user_id', 'otp']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_o_t_p_s');
    }
};
