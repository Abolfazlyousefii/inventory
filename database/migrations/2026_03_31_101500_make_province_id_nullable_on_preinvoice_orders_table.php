<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('preinvoice_orders', 'province_id')) {
            return;
        }

        Schema::table('preinvoice_orders', function (Blueprint $table) {
            $table->unsignedInteger('province_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('preinvoice_orders', 'province_id')) {
            return;
        }

        DB::statement('UPDATE preinvoice_orders SET province_id = 0 WHERE province_id IS NULL');
        Schema::table('preinvoice_orders', function (Blueprint $table) {
            $table->unsignedInteger('province_id')->nullable(false)->change();
        });
    }
};
