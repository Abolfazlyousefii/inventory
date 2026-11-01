<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hall_queue_transitions', function (Blueprint $table) {
            $table->id();
            $table->string('workflow_key', 80);
            $table->string('source_type', 16);
            $table->unsignedBigInteger('source_id');
            $table->string('document_number', 100);
            $table->string('from_stage', 16)->nullable();
            $table->string('to_stage', 16)->nullable();
            $table->string('status_from', 80)->nullable();
            $table->string('status_to', 80);
            $table->dateTime('entered_at', 6);
            $table->index(['workflow_key', 'id'], 'hall_queue_workflow_history');
            $table->index(['entered_at', 'id'], 'hall_queue_recent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hall_queue_transitions');
    }
};
