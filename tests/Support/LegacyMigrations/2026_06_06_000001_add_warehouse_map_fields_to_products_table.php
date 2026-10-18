<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedTinyInteger('warehouse_zone')->nullable()->after('is_sellable');
            $table->json('warehouse_rows')->nullable()->after('warehouse_zone');
            $table->json('warehouse_bins')->nullable()->after('warehouse_rows');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['warehouse_zone', 'warehouse_rows', 'warehouse_bins']);
        });
    }
};
