<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'collection_status')) {
                $table->string('collection_status')->nullable()->after('status_changed_by')->index();
            }
            if (! Schema::hasColumn('invoices', 'collection_completed_at')) {
                $table->timestamp('collection_completed_at')->nullable()->after('collection_status');
            }
            if (! Schema::hasColumn('invoices', 'collection_completed_by')) {
                $table->foreignId('collection_completed_by')->nullable()->after('collection_completed_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('invoices', 'collection_transferred_to_warehouse_at')) {
                $table->timestamp('collection_transferred_to_warehouse_at')->nullable()->after('collection_completed_by');
            }
            if (! Schema::hasColumn('invoices', 'collection_transferred_to_warehouse_by')) {
                $table->foreignId('collection_transferred_to_warehouse_by')->nullable()->after('collection_transferred_to_warehouse_at')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'collection_transferred_to_warehouse_by')) {
                $table->dropConstrainedForeignId('collection_transferred_to_warehouse_by');
            }
            if (Schema::hasColumn('invoices', 'collection_completed_by')) {
                $table->dropConstrainedForeignId('collection_completed_by');
            }
            foreach (['collection_transferred_to_warehouse_at', 'collection_completed_at', 'collection_status'] as $column) {
                if (Schema::hasColumn('invoices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
