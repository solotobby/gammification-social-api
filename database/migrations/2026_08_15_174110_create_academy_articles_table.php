<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academy_articles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('academy_category_id')->nullable()->index();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('body');
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->json('faq_schema')->nullable();
            $table->boolean('published')->default(false)->index();
            $table->string('featured_image')->nullable();
            $table->string('author')->nullable();
            $table->unsignedTinyInteger('seo_score')->default(0);
            $table->unsignedSmallInteger('read_time')->default(1);
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academy_articles');
    }
};
