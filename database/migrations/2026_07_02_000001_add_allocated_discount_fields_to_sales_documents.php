<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preinvoice_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('preinvoice_orders', 'discount_breakdown')) {
                $table->json('discount_breakdown')->nullable()->after('discount_amount');
            }
            if (! Schema::hasColumn('preinvoice_orders', 'invoice_discount_type')) {
                $table->string('invoice_discount_type')->nullable()->after('discount_breakdown');
            }
            if (! Schema::hasColumn('preinvoice_orders', 'invoice_discount_value')) {
                $table->unsignedBigInteger('invoice_discount_value')->default(0)->after('invoice_discount_type');
            }
            if (! Schema::hasColumn('preinvoice_orders', 'invoice_discount_amount')) {
                $table->unsignedBigInteger('invoice_discount_amount')->default(0)->after('invoice_discount_value');
            }
            if (! Schema::hasColumn('preinvoice_orders', 'product_discount_amount')) {
                $table->unsignedBigInteger('product_discount_amount')->default(0)->after('invoice_discount_amount');
            }
            if (! Schema::hasColumn('preinvoice_orders', 'discount_allocation_mode')) {
                $table->string('discount_allocation_mode')->nullable()->after('product_discount_amount');
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'discount_breakdown')) {
                $table->json('discount_breakdown')->nullable()->after('discount_amount');
            }
            if (! Schema::hasColumn('invoices', 'invoice_discount_type')) {
                $table->string('invoice_discount_type')->nullable()->after('discount_breakdown');
            }
            if (! Schema::hasColumn('invoices', 'invoice_discount_value')) {
                $table->unsignedBigInteger('invoice_discount_value')->default(0)->after('invoice_discount_type');
            }
            if (! Schema::hasColumn('invoices', 'invoice_discount_amount')) {
                $table->unsignedBigInteger('invoice_discount_amount')->default(0)->after('invoice_discount_value');
            }
            if (! Schema::hasColumn('invoices', 'product_discount_amount')) {
                $table->unsignedBigInteger('product_discount_amount')->default(0)->after('invoice_discount_amount');
            }
            if (! Schema::hasColumn('invoices', 'discount_allocation_mode')) {
                $table->string('discount_allocation_mode')->nullable()->after('product_discount_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('preinvoice_orders', function (Blueprint $table) {
            foreach (['discount_breakdown', 'invoice_discount_type', 'invoice_discount_value', 'invoice_discount_amount', 'product_discount_amount', 'discount_allocation_mode'] as $column) {
                if (Schema::hasColumn('preinvoice_orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            foreach (['discount_breakdown', 'invoice_discount_type', 'invoice_discount_value', 'invoice_discount_amount', 'product_discount_amount', 'discount_allocation_mode'] as $column) {
                if (Schema::hasColumn('invoices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
