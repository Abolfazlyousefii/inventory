<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('warehouse_transfers', function (Blueprint $table) {
            $table->foreignId('related_invoice_id')->nullable()->after('to_warehouse_id')->constrained('invoices')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->after('related_invoice_id')->constrained('customers')->nullOnDelete();
            $table->string('beneficiary_name')->nullable()->after('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('related_invoice_id');
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn('beneficiary_name');
        });
    }
};
