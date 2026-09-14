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
        Schema::table('user_o_t_p_s', function (Blueprint $table) {
            if (! Schema::hasColumn('user_o_t_p_s', 'type')) {
                $table->string('type')->default('verification')->after('otp')->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_o_t_p_s', function (Blueprint $table) {
            if (Schema::hasColumn('user_o_t_p_s', 'type')) {
                $table->dropColumn('type');
            }
        });
    }
};
