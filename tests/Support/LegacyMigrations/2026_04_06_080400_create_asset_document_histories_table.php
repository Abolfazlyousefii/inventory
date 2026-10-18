<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('asset_document_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('asset_documents')->cascadeOnDelete();
            $table->string('action_type', 64);
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'action_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_document_histories');
    }
};
