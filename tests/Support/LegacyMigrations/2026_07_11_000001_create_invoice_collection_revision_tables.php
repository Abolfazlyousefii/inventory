<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_collection_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->unsignedInteger('revision_number');
            $table->bigInteger('old_total')->default(0);
            $table->bigInteger('new_total')->default(0);
            $table->string('reason_type', 100)->nullable();
            $table->text('reason_note')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['invoice_id', 'revision_number']);
        });

        Schema::create('invoice_collection_revision_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_collection_revision_id')
                ->constrained('invoice_collection_revisions', indexName: 'icri_revision_id_fk')
                ->cascadeOnDelete();
            $table->foreignId('invoice_item_id')->nullable()->constrained('invoice_items')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('change_type', 50);
            $table->string('product_name_snapshot')->nullable();
            $table->string('variant_name_snapshot')->nullable();
            $table->string('sku_snapshot')->nullable();
            $table->integer('old_quantity')->nullable();
            $table->integer('new_quantity')->nullable();
            $table->bigInteger('old_price')->nullable();
            $table->bigInteger('new_price')->nullable();
            $table->bigInteger('old_discount')->nullable();
            $table->bigInteger('new_discount')->nullable();
            $table->bigInteger('old_line_total')->nullable();
            $table->bigInteger('new_line_total')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_collection_revision_items');
        Schema::dropIfExists('invoice_collection_revisions');
    }
};
