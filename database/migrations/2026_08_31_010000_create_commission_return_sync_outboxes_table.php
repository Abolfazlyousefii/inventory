<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('commission_return_sync_outboxes')) {
            return;
        }

        Schema::create('commission_return_sync_outboxes', function (Blueprint $table) {
            $table->id();

            // Intentionally no FK: durable retry metadata should not make a
            // source delete/cleanup transaction fail.
            $table->unsignedBigInteger('sales_return_document_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamps();

            $table->index(
                ['sales_return_document_id', 'id'],
                'commission_return_outbox_document',
            );
            $table->index(
                ['available_at', 'id'],
                'commission_return_outbox_available',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_return_sync_outboxes');
    }
};
