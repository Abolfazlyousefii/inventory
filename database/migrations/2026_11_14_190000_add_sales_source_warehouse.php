<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['preinvoice_order_items', 'preinvoice_draft_reservations', 'invoice_items'] as $name) {
            if (! Schema::hasColumn($name, 'source_warehouse_id')) {
                Schema::table($name, function (Blueprint $table) {
                    // Null retains the historical central-warehouse meaning.
                    $table->foreignId('source_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
                });
            }
        }
    }

    public function down(): void
    { /* Preserve the source of existing sales and reservations. */
    }
};
