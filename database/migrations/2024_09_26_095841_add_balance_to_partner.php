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
        Schema::table('partners', function (Blueprint $table) {
            $table->string('balance_naira')->after('country')->default('0.0');
            $table->string('balance_dollar')->after('country')->default('0.0');
            $table->string('customer_code')->after('country')->nullable();
            $table->string('bank_name')->after('country')->nullable();
            $table->string('account_number')->after('country')->nullable();
            $table->string('account_name')->after('country')->nullable();
            $table->string('currency')->after('country')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn(['balance_naira', 'balance_dollar', 'customer_code', 'bank_name', 'account_number', 'account_name', 'currency']);
        });
    }
};
