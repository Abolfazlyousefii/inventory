<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'warehouse_received_at')) {
                $table->timestamp('warehouse_received_at')->nullable()->after('items_updated_by');
            }
            if (! Schema::hasColumn('invoices', 'warehouse_received_by')) {
                $table->foreignId('warehouse_received_by')->nullable()->after('warehouse_received_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('invoices', 'collection_started_at')) {
                $table->timestamp('collection_started_at')->nullable()->after('warehouse_received_by');
            }
            if (! Schema::hasColumn('invoices', 'collection_started_by')) {
                $table->foreignId('collection_started_by')->nullable()->after('collection_started_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('invoices', 'collected_at')) {
                $table->timestamp('collected_at')->nullable()->after('collection_started_by');
            }
            if (! Schema::hasColumn('invoices', 'collected_by')) {
                $table->foreignId('collected_by')->nullable()->after('collected_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('invoices', 'collection_note')) {
                $table->text('collection_note')->nullable()->after('collected_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            foreach (['warehouse_received_by', 'collection_started_by', 'collected_by'] as $column) {
                if (Schema::hasColumn('invoices', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }
            foreach (['warehouse_received_at', 'collection_started_at', 'collected_at', 'collection_note'] as $column) {
                if (Schema::hasColumn('invoices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
