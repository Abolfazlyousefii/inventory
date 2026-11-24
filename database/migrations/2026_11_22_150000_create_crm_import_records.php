<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_import_records', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);
            $table->string('source_table', 64);
            $table->unsignedBigInteger('source_id');
            $table->char('source_hash', 64);
            $table->json('payload');
            $table->string('target_table', 64)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->timestamp('imported_at');
            $table->unique(['source', 'source_table', 'source_id'], 'crm_import_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_import_records');
    }
};
