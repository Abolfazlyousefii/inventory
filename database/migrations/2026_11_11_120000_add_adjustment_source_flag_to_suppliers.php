<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('suppliers')) {
            return;
        }

        if (! Schema::hasColumn('suppliers', 'is_inventory_adjustment_source')) {
            Schema::table('suppliers', function (Blueprint $table): void {
                $table->boolean('is_inventory_adjustment_source')->default(false);
            });
        }

        // Historical supplier #1 has been independently confirmed as the
        // inventory shortage/surplus placeholder. Never classify by ID alone.
        // Do not touch any purchase, item, movement, ledger, or supplier identity.
        DB::table('suppliers')
            ->where('id', 1)
            ->where('name', 'like', '%کسری%')
            ->where('name', 'like', '%اضاف%')
            ->update(['is_inventory_adjustment_source' => true]);
    }

    public function down(): void
    {
        if (Schema::hasTable('suppliers')
            && Schema::hasColumn('suppliers', 'is_inventory_adjustment_source')) {
            Schema::table('suppliers', function (Blueprint $table): void {
                $table->dropColumn('is_inventory_adjustment_source');
            });
        }
    }
};
