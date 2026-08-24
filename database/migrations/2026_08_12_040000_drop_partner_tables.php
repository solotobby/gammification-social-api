<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('partner_slots');
        Schema::dropIfExists('partners');

        if (Schema::hasColumn('access_codes', 'partner_id')) {
            Schema::table('access_codes', function (Blueprint $table) {
                $table->dropColumn('partner_id');
            });
        }
    }

    public function down(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('country')->nullable();
            $table->boolean('status')->default(false);
            $table->string('account_number')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_name')->nullable();
            $table->string('display_name')->nullable();
            $table->decimal('balance_dollar', 16, 2)->default(0);
            $table->decimal('balance_naira', 16, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('partner_slots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('partner_id');
            $table->integer('influencer')->default(0);
            $table->integer('creator')->default(0);
            $table->integer('beginner')->default(0);
            $table->boolean('status')->default(false);
            $table->timestamps();
        });

        Schema::table('access_codes', function (Blueprint $table) {
            $table->uuid('partner_id')->nullable()->after('level_id');
        });
    }
};
