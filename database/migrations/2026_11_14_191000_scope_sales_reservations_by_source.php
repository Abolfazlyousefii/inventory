<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('preinvoice_draft_reservations', function (Blueprint $table) {
            // Null is historical central; a generated key also protects NULL rows from duplicates.
            $table->unsignedBigInteger('source_warehouse_key')->virtualAs('coalesce(source_warehouse_id, 0)');
            $table->unique(['token','product_id','variant_id','source_warehouse_key'], 'preinvoice_res_source_unique');
            $table->dropUnique('preinvoice_draft_res_unique');
        });
    }
    public function down(): void
    {
        // Keep warehouse provenance and valid multi-source reservations intact.
    }
};
