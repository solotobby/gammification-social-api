<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_reports', function (Blueprint $table) {
            $table->string('status')->default('pending')->after('reason');
            $table->string('resolution')->nullable()->after('status');
            $table->uuid('reviewed_by')->nullable()->after('resolution');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');

            $table->index(['status', 'created_at']);
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('post_reports', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            $table->dropIndex(['status', 'created_at']);
            $table->dropColumn(['status', 'resolution', 'reviewed_by', 'reviewed_at']);
        });
    }
};
