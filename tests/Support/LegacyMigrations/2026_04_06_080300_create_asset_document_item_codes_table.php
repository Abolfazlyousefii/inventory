<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('asset_document_item_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_item_id')->constrained('asset_document_items')->cascadeOnDelete();
            $table->string('asset_code', 4)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_document_item_codes');
    }
};
