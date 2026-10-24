<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('warehouse_transfers', function (Blueprint $table) {
            $table->string('voucher_type', 30)
                ->default('between_warehouses')
                ->after('reference')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_transfers', function (Blueprint $table) {
            $table->dropColumn('voucher_type');
        });
    }
};
