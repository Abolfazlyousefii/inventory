<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_transfer_items', function (Blueprint $table) {
            if (!Schema::hasColumn('warehouse_transfer_items', 'return_kind')) {
                $table->string('return_kind', 20)->nullable()->after('line_total')->index();
            }
            if (!Schema::hasColumn('warehouse_transfer_items', 'destination_warehouse_id')) {
                $table->foreignId('destination_warehouse_id')->nullable()->after('return_kind')->constrained('warehouses')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_transfer_items', function (Blueprint $table) {
            if (Schema::hasColumn('warehouse_transfer_items', 'destination_warehouse_id')) {
                $table->dropConstrainedForeignId('destination_warehouse_id');
            }
            if (Schema::hasColumn('warehouse_transfer_items', 'return_kind')) {
                $table->dropColumn('return_kind');
            }
        });
    }
};
