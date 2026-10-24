<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('warehouse_transfer_items', function (Blueprint $table) {
            if (!Schema::hasColumn('warehouse_transfer_items', 'invoice_item_id')) {
                $table->foreignId('invoice_item_id')
                    ->nullable()
                    ->after('warehouse_transfer_id')
                    ->constrained('invoice_items')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_transfer_items', function (Blueprint $table) {
            if (Schema::hasColumn('warehouse_transfer_items', 'invoice_item_id')) {
                $table->dropConstrainedForeignId('invoice_item_id');
            }
        });
    }
};
