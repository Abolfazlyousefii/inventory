<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('preinvoice_orders', 'description')) {
            Schema::table('preinvoice_orders', function (Blueprint $table) {
                $table->text('description')->nullable()->after('customer_address');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('preinvoice_orders', 'description')) {
            Schema::table('preinvoice_orders', function (Blueprint $table) {
                $table->dropColumn('description');
            });
        }
    }
};
