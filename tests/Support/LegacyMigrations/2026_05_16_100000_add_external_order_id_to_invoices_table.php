<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'external_order_id')) {
                $table->unsignedBigInteger('external_order_id')->nullable()->unique()->after('preinvoice_order_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'external_order_id')) {
                $table->dropUnique(['external_order_id']);
                $table->dropColumn('external_order_id');
            }
        });
    }
};

