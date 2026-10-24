<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_count_document_items', function (Blueprint $table) {

            // 1️⃣ حذف FK های وابسته (خیلی مهم)
            $table->dropForeign(['document_id']);
            $table->dropForeign(['product_id']);

            // 2️⃣ حذف unique قدیمی
            $table->dropUnique('stock_count_document_items_document_id_product_id_unique');

            // 3️⃣ اضافه کردن ستون variant
            $table->foreignId('product_variant_id')
                ->nullable()
                ->after('product_id')
                ->constrained('product_variants')
                ->restrictOnDelete();

            // 4️⃣ ساخت unique جدید
            $table->unique(
                ['document_id', 'product_id', 'product_variant_id'],
                'stock_count_doc_product_variant_unique'
            );

            // 5️⃣ اضافه کردن دوباره FK ها
            $table->foreign('document_id')
                ->references('id')
                ->on('stock_count_documents')
                ->cascadeOnDelete();

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_count_document_items', function (Blueprint $table) {

            // حذف FK ها
            $table->dropForeign(['document_id']);
            $table->dropForeign(['product_id']);

            $table->dropUnique('stock_count_doc_product_variant_unique');

            $table->dropConstrainedForeignId('product_variant_id');

            // بازگرداندن unique قبلی
            $table->unique(
                ['document_id', 'product_id'],
                'stock_count_document_items_document_id_product_id_unique'
            );

            // بازگرداندن FK ها
            $table->foreign('document_id')
                ->references('id')
                ->on('stock_count_documents')
                ->cascadeOnDelete();

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->restrictOnDelete();
        });
    }
};