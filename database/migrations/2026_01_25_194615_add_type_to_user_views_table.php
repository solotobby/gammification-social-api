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
        Schema::table('user_views', function (Blueprint $table) {
            $table->enum('type', ['view', 'self-view'])->default('view')->after('poster_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_views', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
