<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preinvoice_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('preinvoice_orders', 'stock_frozen_until')) {
                $table->timestamp('stock_frozen_until')->nullable()->after('warehouse_reviewed_at');
            }
            if (!Schema::hasColumn('preinvoice_orders', 'stock_released_at')) {
                $table->timestamp('stock_released_at')->nullable()->after('stock_frozen_until');
            }
        });
    }

    public function down(): void
    {
        Schema::table('preinvoice_orders', function (Blueprint $table) {
            if (Schema::hasColumn('preinvoice_orders', 'stock_released_at')) {
                $table->dropColumn('stock_released_at');
            }
            if (Schema::hasColumn('preinvoice_orders', 'stock_frozen_until')) {
                $table->dropColumn('stock_frozen_until');
            }
        });
    }
};
