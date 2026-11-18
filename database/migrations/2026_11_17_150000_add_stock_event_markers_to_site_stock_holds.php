<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Some test installations provision the cross-app hold tables from a
        // separate schema. Do not synthesize incomplete production hold tables.
        if (! Schema::hasTable('site_stock_holds')) {
            return;
        }

        if (! Schema::hasColumn('site_stock_holds', 'stock_applied_at')) {
            Schema::table('site_stock_holds', fn (Blueprint $table) =>
                $table->timestamp('stock_applied_at')->nullable());
        }
        if (! Schema::hasColumn('site_stock_holds', 'stock_returned_at')) {
            Schema::table('site_stock_holds', fn (Blueprint $table) =>
                $table->timestamp('stock_returned_at')->nullable());
        }
    }

    public function down(): void
    {
        // Intentionally retain replay markers. Dropping them after a stock
        // change would erase durable idempotency state and invite re-credits
        // if older Site holds are subsequently processed again.
    }
};
