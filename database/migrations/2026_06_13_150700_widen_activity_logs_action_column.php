<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('activity_logs') || ! Schema::hasColumn('activity_logs', 'action')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            // SQLite does not enforce VARCHAR length, so the widened schema is already equivalent.
            return;
        }

        DB::statement('ALTER TABLE activity_logs MODIFY action VARCHAR(100) NOT NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('activity_logs') || ! Schema::hasColumn('activity_logs', 'action')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            // SQLite does not enforce VARCHAR length, so no physical column rewrite is needed.
            return;
        }

        DB::statement('ALTER TABLE activity_logs MODIFY action VARCHAR(30) NOT NULL');
    }
};
