<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('inventory_webhook_logs', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempts')->default(0)->after('status');
            $table->string('target', 2048)->nullable()->after('event');
            $table->timestamp('next_retry_at')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_webhook_logs', function (Blueprint $table) {
            $table->dropColumn(['attempts', 'target', 'next_retry_at']);
        });
    }
};
