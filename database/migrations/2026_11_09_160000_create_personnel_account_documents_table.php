<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnel_account_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('type', 10);
            $table->unsignedBigInteger('amount');
            $table->date('document_date');
            $table->text('description');
            $table->string('reference', 190)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('submission_token')->unique();
            $table->timestamps();
            $table->index(['customer_id', 'document_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personnel_account_documents');
    }
};
