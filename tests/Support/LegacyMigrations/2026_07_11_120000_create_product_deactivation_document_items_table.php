<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_deactivation_document_items')) {
            return;
        }

        Schema::create('product_deactivation_document_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('subcategory_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->string('deactivation_type', 50);
            $table->string('deactivation_status', 30)->default('deactivated');
            $table->string('category_name_snapshot')->nullable();
            $table->string('subcategory_name_snapshot')->nullable();
            $table->string('product_name_snapshot')->nullable();
            $table->string('variant_name_snapshot')->nullable();
            $table->timestamps();

            $table->foreign('document_id', 'pddi_document_fk')->references('id')->on('product_deactivation_documents')->cascadeOnDelete();
            $table->foreign('category_id', 'pddi_category_fk')->references('id')->on('categories')->nullOnDelete();
            $table->foreign('subcategory_id', 'pddi_subcategory_fk')->references('id')->on('categories')->nullOnDelete();
            $table->foreign('product_id', 'pddi_product_fk')->references('id')->on('products')->nullOnDelete();
            $table->foreign('variant_id', 'pddi_variant_fk')->references('id')->on('product_variants')->nullOnDelete();
            $table->index('document_id', 'pddi_document_idx');
            $table->index('product_id', 'pddi_product_idx');
            $table->index('variant_id', 'pddi_variant_idx');
            $table->unique(['document_id', 'variant_id'], 'pddi_document_variant_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_deactivation_document_items');
    }
};
