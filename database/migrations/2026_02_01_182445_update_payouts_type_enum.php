<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // Update enum column to include new types
        DB::statement("ALTER TABLE payouts MODIFY COLUMN type ENUM('Freemium','Premium','Bonus','Past') DEFAULT 'Premium' NOT NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // Revert back to original enum
        DB::statement("ALTER TABLE payouts MODIFY COLUMN type ENUM('Freemium','Premium') DEFAULT 'Premium' NOT NULL");
    }
};
