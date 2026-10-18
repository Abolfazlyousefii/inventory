<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('asset_document_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('asset_documents')->cascadeOnDelete();
            $table->string('item_name');
            $table->unsignedInteger('quantity');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_document_items');
    }
};
