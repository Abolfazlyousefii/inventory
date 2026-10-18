<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('asset_documents', function (Blueprint $table) {
            $table->id();
            $table->string('document_number')->unique();
            $table->date('document_date');
            $table->foreignId('personnel_id')->constrained('asset_personnel')->restrictOnDelete();
            $table->string('status')->default('draft');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'document_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_documents');
    }
};
