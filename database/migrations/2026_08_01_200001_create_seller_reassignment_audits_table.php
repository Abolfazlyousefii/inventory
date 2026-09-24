<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_reassignment_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('preinvoice_id')->nullable()->constrained('preinvoice_orders')->nullOnDelete();
            $table->foreignId('old_seller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('new_seller_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->string('source', 20);
            $table->text('reason');
            $table->timestamp('changed_at');
            $table->string('operation_key')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('seller_reassignment_audits'); }
};
