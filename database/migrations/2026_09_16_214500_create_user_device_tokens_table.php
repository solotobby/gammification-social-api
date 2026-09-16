<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_device_tokens')) {
            Schema::create('user_device_tokens', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('token')->unique();
                $table->string('platform', 20)->nullable(); // ios, android, web
                $table->string('device_name')->nullable(); // e.g. iPhone 15 Pro, Samsung S24
                $table->string('ip_address', 45)->nullable(); // IPv4 or IPv6
                $table->string('location_type', 64)->nullable(); // e.g. cellular, wifi, home, office
                $table->boolean('is_logged_out')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_active_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'is_active']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_device_tokens');
    }
};
