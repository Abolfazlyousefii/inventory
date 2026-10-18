<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('warehouse_location_movements');
        Schema::dropIfExists('warehouse_location_stocks');
        Schema::dropIfExists('warehouse_locations');

        Schema::create('warehouse_locations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id');
            $table->string('zone', 20);
            $table->string('rack', 20);
            $table->string('box', 20);
            $table->string('code', 80)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['warehouse_id', 'zone', 'rack', 'box'], 'wl_wh_z_r_b_uq');
            $table->foreign('warehouse_id', 'wl_wh_fk')->references('id')->on('warehouses')->cascadeOnDelete();
        });

        Schema::create('warehouse_location_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedBigInteger('warehouse_location_id');
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('reserved_quantity')->default(0);
            $table->timestamps();

            $table->unique(['warehouse_id', 'product_variant_id', 'warehouse_location_id'], 'wls_wh_var_loc_uq');
            $table->index(['warehouse_id', 'product_variant_id'], 'wls_wh_var_idx');
            $table->index('warehouse_location_id', 'wls_loc_idx');
            $table->foreign('warehouse_id', 'wls_wh_fk')->references('id')->on('warehouses')->cascadeOnDelete();
            $table->foreign('product_variant_id', 'wls_var_fk')->references('id')->on('product_variants')->cascadeOnDelete();
            $table->foreign('warehouse_location_id', 'wls_loc_fk')->references('id')->on('warehouse_locations')->cascadeOnDelete();
        });

        Schema::create('warehouse_location_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedBigInteger('from_location_id')->nullable();
            $table->unsignedBigInteger('to_location_id')->nullable();
            $table->unsignedInteger('quantity');
            $table->string('type', 40);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['warehouse_id', 'product_variant_id', 'type'], 'wlm_wh_var_type_idx');
            $table->index('from_location_id', 'wlm_from_loc_idx');
            $table->index('to_location_id', 'wlm_to_loc_idx');
            $table->index(['reference_type', 'reference_id'], 'wlm_ref_idx');
            $table->foreign('warehouse_id', 'wlm_wh_fk')->references('id')->on('warehouses')->cascadeOnDelete();
            $table->foreign('product_variant_id', 'wlm_var_fk')->references('id')->on('product_variants')->cascadeOnDelete();
            $table->foreign('from_location_id', 'wlm_from_fk')->references('id')->on('warehouse_locations')->nullOnDelete();
            $table->foreign('to_location_id', 'wlm_to_fk')->references('id')->on('warehouse_locations')->nullOnDelete();
            $table->foreign('user_id', 'wlm_user_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_location_movements');
        Schema::dropIfExists('warehouse_location_stocks');
        Schema::dropIfExists('warehouse_locations');
    }
};
