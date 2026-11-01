<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Complete older bundle installations without changing any saved stock or prices. */
    public function up(): void
    {
        if (Schema::hasTable('products')) {
            foreach (['bundle_site_auto_publish', 'bundle_quantity_mode', 'bundle_shared_remaining'] as $column) {
                if (Schema::hasColumn('products', $column)) continue;
                Schema::table('products', function (Blueprint $table) use ($column) {
                    if ($column === 'bundle_site_auto_publish') $table->boolean($column)->nullable();
                    elseif ($column === 'bundle_quantity_mode') $table->string($column, 16)->nullable();
                    else $table->unsignedInteger($column)->nullable();
                });
            }
        }
        if (Schema::hasTable('product_bundle_components')) {
            if (!Schema::hasColumn('product_bundle_components', 'discount_type')) {
                Schema::table('product_bundle_components', fn (Blueprint $t) => $t->string('discount_type', 16)->default('percent'));
            }
            if (!Schema::hasColumn('product_bundle_components', 'discount_value')) {
                Schema::table('product_bundle_components', fn (Blueprint $t) => $t->unsignedBigInteger('discount_value')->default(0));
            }
        }
        if (!Schema::hasTable('bundle_assemblies')) {
            Schema::create('bundle_assemblies', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('bundle_variant_id')->index();
                $t->unsignedBigInteger('warehouse_id')->index();
                $t->unsignedBigInteger('performed_by')->nullable();
                $t->string('operation', 32);
                $t->unsignedInteger('quantity');
                $t->json('components_snapshot');
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Dropping these columns after use would erase pricing and publication decisions.
        throw new RuntimeException('Forward-only: preserve saved bundle pricing and assembly history.');
    }
};
