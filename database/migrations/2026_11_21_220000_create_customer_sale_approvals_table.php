<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_sale_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('assignment_version');
            $table->uuid('context_token');
            $table->string('code_hash');
            $table->string('recipient_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('code_expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('approval_expires_at')->nullable();
            $table->foreignId('preinvoice_order_id')->nullable()->constrained('preinvoice_orders')->nullOnDelete();
            $table->timestamp('bound_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['requester_id', 'context_token', 'customer_id'], 'sale_approval_context_customer');
            $table->index(['requester_id', 'preinvoice_order_id']);
        });
    }

    public function down(): void { Schema::dropIfExists('customer_sale_approvals'); }
};
