<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_device_tokens', function (Blueprint $table) {
            if (! Schema::hasColumn('user_device_tokens', 'device_id')) {
                $table->string('device_id', 255)->nullable()->after('device_name')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_device_tokens', function (Blueprint $table) {
            if (Schema::hasColumn('user_device_tokens', 'device_id')) {
                $table->dropColumn('device_id');
            }
        });
    }
};
