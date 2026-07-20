<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_return_document_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('sales_return_documents')->cascadeOnDelete();
            $table->string('action', 40);
            $table->string('token', 80)->nullable()->unique();
            $table->text('reason');
            $table->json('before_snapshot');
            $table->json('after_snapshot')->nullable();
            $table->unsignedBigInteger('previous_total')->default(0);
            $table->unsignedBigInteger('new_total')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['document_id', 'action']);
        });
    }
    public function down(): void { Schema::dropIfExists('sales_return_document_revisions'); }
};
