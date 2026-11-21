<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_return_document_items', function (Blueprint $table) {
            $table->unique(['document_id', 'invoice_item_id', 'destination_warehouse_id'], 'srdi_doc_invoice_destination_unique');
            $table->dropUnique('srdi_doc_inv_item_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('sales_return_document_items')->whereNotNull('invoice_item_id')
            ->select('document_id', 'invoice_item_id')->groupBy('document_id', 'invoice_item_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot restore the old constraint while destination partitions exist; no records were changed.');
        }
        Schema::table('sales_return_document_items', function (Blueprint $table) {
            $table->unique(['document_id', 'invoice_item_id'], 'srdi_doc_inv_item_unique');
            $table->dropUnique('srdi_doc_invoice_destination_unique');
        });
    }
};
