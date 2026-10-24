<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preinvoice_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('preinvoice_orders', 'external_order_id')) {
                $table->unsignedBigInteger('external_order_id')->nullable()->unique()->after('uuid');
            }
        });
    }

    public function down(): void
    {
        Schema::table('preinvoice_orders', function (Blueprint $table) {
            if (Schema::hasColumn('preinvoice_orders', 'external_order_id')) {
                $table->dropUnique(['external_order_id']);
                $table->dropColumn('external_order_id');
            }
        });
    }
};

