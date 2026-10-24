<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preinvoice_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('preinvoice_orders', 'items_updated_at')) {
                $table->timestamp('items_updated_at')->nullable()->after('stock_released_at');
            }
            if (! Schema::hasColumn('preinvoice_orders', 'items_updated_by')) {
                $table->foreignId('items_updated_by')->nullable()->after('items_updated_at')->constrained('users')->nullOnDelete();
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'items_updated_at')) {
                $table->timestamp('items_updated_at')->nullable()->after('status_changed_by');
            }
            if (! Schema::hasColumn('invoices', 'items_updated_by')) {
                $table->foreignId('items_updated_by')->nullable()->after('items_updated_at')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'items_updated_by')) {
                $table->dropConstrainedForeignId('items_updated_by');
            }
            if (Schema::hasColumn('invoices', 'items_updated_at')) {
                $table->dropColumn('items_updated_at');
            }
        });

        Schema::table('preinvoice_orders', function (Blueprint $table) {
            if (Schema::hasColumn('preinvoice_orders', 'items_updated_by')) {
                $table->dropConstrainedForeignId('items_updated_by');
            }
            if (Schema::hasColumn('preinvoice_orders', 'items_updated_at')) {
                $table->dropColumn('items_updated_at');
            }
        });
    }
};
