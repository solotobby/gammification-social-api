<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_articles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('help_category_id')->nullable()->index();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('body');
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->boolean('published')->default(false)->index();
            $table->unsignedSmallInteger('sort_order')->default(0)->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_articles');
    }
};
