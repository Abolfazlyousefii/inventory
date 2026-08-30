<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_invoice_sync_outboxes', function (Blueprint $table) {
            $table->id();
            // Intentionally no FK: this row must survive a hard invoice delete
            // long enough to reconcile historical ledger snapshots.
            $table->unsignedBigInteger('invoice_id');
            $table->string('invoice_number_snapshot')->nullable();
            $table->dateTime('old_date')->nullable();
            $table->dateTime('new_date')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'id'], 'commission_sync_outbox_invoice');
            $table->index(['available_at', 'id'], 'commission_sync_outbox_available');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_invoice_sync_outboxes');
    }
};
