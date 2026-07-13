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
        // Update enum column to include new types
        DB::statement("ALTER TABLE payouts MODIFY COLUMN type ENUM('Freemium','Premium','Bonus','Past') DEFAULT 'Premium' NOT NULL");
    }

    public function down(): void
    {
        // Revert back to original enum
        DB::statement("ALTER TABLE payouts MODIFY COLUMN type ENUM('Freemium','Premium') DEFAULT 'Premium' NOT NULL");
    }
};
