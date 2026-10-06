<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cancelled_invoice_reissues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('original_invoice_id')
                ->unique()
                ->constrained('invoices')
                ->restrictOnDelete();
            $table->foreignId('replacement_preinvoice_order_id')
                ->unique()
                ->constrained('preinvoice_orders')
                ->cascadeOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cancelled_invoice_reissues');
    }
};
