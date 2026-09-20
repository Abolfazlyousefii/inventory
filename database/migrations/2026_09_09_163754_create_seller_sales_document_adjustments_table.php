<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'seller_sales_document_adjustments';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_sales_document_id');
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->bigInteger('amount');
            $table->string('reason', 2000);
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->foreign('seller_sales_document_id', 'ssd_adjustments_document_id_foreign')
                ->references('id')->on('seller_sales_documents')
                ->restrictOnDelete();
            $table->foreign('invoice_id', 'ssd_adjustments_invoice_id_foreign')
                ->references('id')->on('invoices')
                ->nullOnDelete();
            $table->foreign('created_by', 'ssd_adjustments_created_by_foreign')
                ->references('id')->on('users')
                ->restrictOnDelete();

            $table->index('seller_sales_document_id', 'ssd_adjustments_document_id_idx');
            $table->index('invoice_id', 'ssd_adjustments_invoice_id_idx');
            $table->index('created_by', 'ssd_adjustments_created_by_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
