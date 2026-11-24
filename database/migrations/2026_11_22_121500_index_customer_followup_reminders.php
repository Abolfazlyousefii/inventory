<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_followups', function (Blueprint $table) {
            $table->index(['status', 'reminded_at', 'due_at', 'id'], 'customer_followups_due_reminder_index');
        });
    }

    public function down(): void
    {
        Schema::table('customer_followups', fn (Blueprint $table) => $table->dropIndex('customer_followups_due_reminder_index'));
    }
};
