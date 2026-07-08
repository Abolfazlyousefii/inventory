<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'shipping_method_id')) {
                $table->foreignId('shipping_method_id')->nullable()->after('shipping_price')->constrained('shipping_methods')->nullOnDelete();
            }

            if (! Schema::hasColumn('invoices', 'shipping_cost')) {
                $table->unsignedBigInteger('shipping_cost')->default(0)->after('shipping_method_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'shipping_method_id')) {
                $table->dropConstrainedForeignId('shipping_method_id');
            }

            if (Schema::hasColumn('invoices', 'shipping_cost')) {
                $table->dropColumn('shipping_cost');
            }
        });
    }
};
