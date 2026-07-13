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
        Schema::create('level_plan_ids', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('level_id')->index();
            $table->string('level_name');
            $table->string('provider');
            $table->string('plan_code');
            $table->decimal('amount', 10, 2)->default(0.00);
            $table->string('currency');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('level_plan_ids');
    }
};
