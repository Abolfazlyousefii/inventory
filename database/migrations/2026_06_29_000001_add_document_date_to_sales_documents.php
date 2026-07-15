<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preinvoice_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('preinvoice_orders', 'document_date')) {
                $table->timestamp('document_date')->nullable()->after('created_by')->index();
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'document_date')) {
                $table->timestamp('document_date')->nullable()->after('preinvoice_order_id')->index();
            }
        });

        DB::table('preinvoice_orders')
            ->whereNull('document_date')
            ->update(['document_date' => DB::raw('created_at')]);

        DB::statement(<<<'SQL'
            UPDATE invoices i
            LEFT JOIN preinvoice_orders p ON p.id = i.preinvoice_order_id
            SET i.document_date = COALESCE(p.document_date, p.created_at, i.created_at)
            WHERE i.document_date IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'document_date')) {
                $table->dropColumn('document_date');
            }
        });

        Schema::table('preinvoice_orders', function (Blueprint $table) {
            if (Schema::hasColumn('preinvoice_orders', 'document_date')) {
                $table->dropColumn('document_date');
            }
        });
    }
};
