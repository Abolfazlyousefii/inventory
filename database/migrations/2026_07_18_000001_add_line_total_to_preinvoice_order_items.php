<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preinvoice_order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('preinvoice_order_items', 'line_total')) {
                $table->unsignedBigInteger('line_total')->default(0)->after('price');
            }
        });

        if (Schema::hasColumn('preinvoice_order_items', 'line_total')) {
            DB::table('preinvoice_order_items')->update([
                'line_total' => DB::raw('GREATEST((quantity * price) - COALESCE(line_discount_amount, 0), 0)'),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('preinvoice_order_items', function (Blueprint $table) {
            if (Schema::hasColumn('preinvoice_order_items', 'line_total')) {
                $table->dropColumn('line_total');
            }
        });
    }
};
