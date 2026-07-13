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
        Schema::table('user_levels', function (Blueprint $table) {
            $table->string('email_token')->nullable()->after('level_id');
            $table->string('plan_name')->nullable()->after('level_id');
            $table->string('plan_code')->nullable()->after('plan_name');
            $table->string('subscription_code')->nullable()->after('plan_code');
            $table->timestamp('start_date')->nullable()->after('plan_name');
            $table->timestamp('next_payment_date')->nullable()->after('start_date');
            $table->enum('status', ['active', 'inactive'])->default('active')->after('next_payment_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_levels', function (Blueprint $table) {
            $table->dropColumn(['email_token', 'plan_name', 'plan_code', 'start_date', 'subscription_code', 'next_payment_date', 'status']);
        });
    }
};
