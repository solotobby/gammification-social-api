<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            if (! Schema::hasColumn('posts', 'is_boosted')) {
                $table->boolean('is_boosted')->default(false)->after('status');
                $table->index('is_boosted');
            }
            if (! Schema::hasColumn('posts', 'monetization_paused')) {
                $table->boolean('monetization_paused')->default(false)->after('is_boosted');
            }
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            if (Schema::hasColumn('posts', 'is_boosted')) {
                $table->dropIndex(['is_boosted']);
                $table->dropColumn('is_boosted');
            }
            if (Schema::hasColumn('posts', 'monetization_paused')) {
                $table->dropColumn('monetization_paused');
            }
        });
    }
};
