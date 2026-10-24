<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('preinvoice_orders', 'status')) {
            Schema::table('preinvoice_orders', function (Blueprint $table) {
                $table->string('status')->default('draft')->after('created_by');
            });
        }

        // add index only if needed and not already present
    }

    public function down(): void
    {
        if (Schema::hasColumn('preinvoice_orders', 'status')) {
            Schema::table('preinvoice_orders', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }
    }
};