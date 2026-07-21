<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('stock_movements') || !Schema::hasColumn('stock_movements', 'reason')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            // SQLite stores Laravel enum/string columns as text-compatible columns in tests.
            return;
        }

        DB::statement("ALTER TABLE `stock_movements` MODIFY `reason` VARCHAR(100) NOT NULL DEFAULT 'adjustment'");
    }

    public function down(): void
    {
        if (!Schema::hasTable('stock_movements') || !Schema::hasColumn('stock_movements', 'reason')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            // SQLite stores Laravel enum/string columns as text-compatible columns in tests.
            return;
        }

        DB::statement("ALTER TABLE `stock_movements` MODIFY `reason` ENUM('purchase','sale','return','transfer','adjustment') NOT NULL DEFAULT 'adjustment'");
    }
};
