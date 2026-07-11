<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stock_count_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_count_documents', 'type')) $table->string('type', 20)->default('product')->after('document_number');
            if (! Schema::hasColumn('stock_count_documents', 'product_id')) $table->foreignId('product_id')->nullable()->after('warehouse_id')->constrained('products')->restrictOnDelete();
            if (! Schema::hasColumn('stock_count_documents', 'variants_count')) $table->unsignedInteger('variants_count')->default(0)->after('description');
            if (! Schema::hasColumn('stock_count_documents', 'counted_count')) $table->unsignedInteger('counted_count')->default(0)->after('variants_count');
            if (! Schema::hasColumn('stock_count_documents', 'zeroed_count')) $table->unsignedInteger('zeroed_count')->default(0)->after('counted_count');
            if (! Schema::hasColumn('stock_count_documents', 'increased_count')) $table->unsignedInteger('increased_count')->default(0)->after('zeroed_count');
            if (! Schema::hasColumn('stock_count_documents', 'decreased_count')) $table->unsignedInteger('decreased_count')->default(0)->after('increased_count');
            if (! Schema::hasColumn('stock_count_documents', 'total_before')) $table->bigInteger('total_before')->default(0)->after('decreased_count');
            if (! Schema::hasColumn('stock_count_documents', 'total_actual')) $table->bigInteger('total_actual')->default(0)->after('total_before');
            if (! Schema::hasColumn('stock_count_documents', 'total_increase')) $table->bigInteger('total_increase')->default(0)->after('total_actual');
            if (! Schema::hasColumn('stock_count_documents', 'total_decrease')) $table->bigInteger('total_decrease')->default(0)->after('total_increase');
            if (! Schema::hasColumn('stock_count_documents', 'cancelled_by')) $table->foreignId('cancelled_by')->nullable()->after('finalized_at')->constrained('users')->nullOnDelete();
            if (! Schema::hasColumn('stock_count_documents', 'cancelled_at')) $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            if (! Schema::hasColumn('stock_count_documents', 'cancel_reason')) $table->text('cancel_reason')->nullable()->after('cancelled_at');
        });

        Schema::table('stock_count_document_items', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_count_document_items', 'product_variant_id')) $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained('product_variants')->restrictOnDelete();
            if (! Schema::hasColumn('stock_count_document_items', 'warehouse_id')) $table->foreignId('warehouse_id')->nullable()->after('document_id')->constrained('warehouses')->restrictOnDelete();
            if (! Schema::hasColumn('stock_count_document_items', 'warehouse_stock_id')) $table->foreignId('warehouse_stock_id')->nullable()->after('product_variant_id')->constrained('warehouse_stocks')->nullOnDelete();
            if (! Schema::hasColumn('stock_count_document_items', 'product_name_snapshot')) $table->string('product_name_snapshot')->nullable()->after('warehouse_stock_id');
            if (! Schema::hasColumn('stock_count_document_items', 'variant_name_snapshot')) $table->string('variant_name_snapshot')->nullable()->after('product_name_snapshot');
            if (! Schema::hasColumn('stock_count_document_items', 'sku_snapshot')) $table->string('sku_snapshot')->nullable()->after('variant_name_snapshot');
            if (! Schema::hasColumn('stock_count_document_items', 'system_available_at_start')) $table->integer('system_available_at_start')->default(0)->after('sku_snapshot');
            if (! Schema::hasColumn('stock_count_document_items', 'reserved_at_start')) $table->integer('reserved_at_start')->default(0)->after('system_available_at_start');
            if (! Schema::hasColumn('stock_count_document_items', 'expected_physical_at_start')) $table->integer('expected_physical_at_start')->default(0)->after('reserved_at_start');
            if (! Schema::hasColumn('stock_count_document_items', 'new_available')) $table->integer('new_available')->nullable()->after('actual_quantity');
            if (! Schema::hasColumn('stock_count_document_items', 'warehouse_stock_updated_at_start')) $table->timestamp('warehouse_stock_updated_at_start')->nullable()->after('difference_quantity');
            if (! Schema::hasColumn('stock_count_document_items', 'stock_updated_at_start')) $table->timestamp('stock_updated_at_start')->nullable()->after('warehouse_stock_updated_at_start');
        });

        try { DB::statement('ALTER TABLE stock_count_document_items DROP INDEX stock_count_document_items_document_id_product_id_unique'); } catch (Throwable $e) {}
        try { DB::statement('ALTER TABLE stock_count_document_items MODIFY actual_quantity INT NULL'); } catch (Throwable $e) {}
    }

    public function down(): void {}
};
