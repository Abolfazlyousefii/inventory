<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_variants') && ! Schema::hasColumn('product_variants', 'sales_enabled')) {
            Schema::table('product_variants', function (Blueprint $table) {
                $table->boolean('sales_enabled')->default(true)->after('is_active')->index('pv_sales_enabled_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('product_variants') && Schema::hasColumn('product_variants', 'sales_enabled')) {
            Schema::table('product_variants', function (Blueprint $table) {
                $table->dropIndex('pv_sales_enabled_idx');
                $table->dropColumn('sales_enabled');
            });
        }
    }
};
