<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['preinvoice_orders', 'invoices'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->decimal('invoice_discount_value', 22, 2)->unsigned()->default(0)->change());
        }
    }

    public function down(): void
    {
        // Never silently truncate new fractional percentages during a rollback.
        foreach (['preinvoice_orders', 'invoices'] as $name) {
            if (DB::table($name)->whereRaw('invoice_discount_value <> FLOOR(invoice_discount_value)')->exists()) {
                throw new RuntimeException('Fractional discount values exist; retain the compatible decimal schema.');
            }
        }
        foreach (['preinvoice_orders', 'invoices'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->unsignedBigInteger('invoice_discount_value')->default(0)->change());
        }
    }
};
