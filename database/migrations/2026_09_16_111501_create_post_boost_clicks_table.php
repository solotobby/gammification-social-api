<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('post_boost_clicks')) {
            Schema::create('post_boost_clicks', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('post_boost_id');
                $table->uuid('post_id');
                $table->uuid('user_id')->nullable();
                $table->string('platform', 32)->default('payhankey'); // payhankey, partner
                $table->string('ip', 45)->nullable();
                $table->string('country', 100)->nullable();
                $table->string('region', 100)->nullable();
                $table->string('city', 100)->nullable();
                $table->string('device', 32)->nullable(); // Mobile, Tablet, Desktop
                $table->string('browser', 64)->nullable(); // Chrome, Safari, Firefox, Edge, etc.
                $table->string('os', 64)->nullable(); // iOS, Android, macOS, Windows, Linux
                $table->text('user_agent')->nullable();
                $table->text('referrer')->nullable();
                $table->timestamps();

                $table->index(['post_boost_id', 'created_at']);
                $table->index(['post_id', 'created_at']);
                $table->index(['platform', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('post_boost_clicks');
    }
};
