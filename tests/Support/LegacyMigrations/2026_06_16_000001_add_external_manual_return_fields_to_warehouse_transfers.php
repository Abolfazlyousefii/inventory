<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('warehouse_transfers', function (Blueprint $table) {
            if (!Schema::hasColumn('warehouse_transfers', 'return_type')) {
                $table->string('return_type', 30)->default('internal_invoice')->after('related_invoice_id')->index();
            }

            if (!Schema::hasColumn('warehouse_transfers', 'external_invoice_number')) {
                $table->string('external_invoice_number', 100)->nullable()->after('return_type')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_transfers', function (Blueprint $table) {
            if (Schema::hasColumn('warehouse_transfers', 'external_invoice_number')) {
                $table->dropColumn('external_invoice_number');
            }

            if (Schema::hasColumn('warehouse_transfers', 'return_type')) {
                $table->dropColumn('return_type');
            }
        });
    }
};
