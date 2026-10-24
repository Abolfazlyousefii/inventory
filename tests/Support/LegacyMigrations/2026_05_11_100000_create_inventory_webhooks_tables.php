<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_webhook_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_enabled')->default(false);
            $table->string('endpoint_url')->nullable();
            $table->string('secret')->nullable();
            $table->unsignedInteger('timeout_seconds')->default(5);
            $table->timestamps();
        });

        Schema::create('inventory_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('setting_id')->nullable()->constrained('inventory_webhook_settings')->nullOnDelete();
            $table->string('event');
            $table->string('status', 30)->default('pending');
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('error_message')->nullable();
            $table->json('payload');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_webhook_logs');
        Schema::dropIfExists('inventory_webhook_settings');
    }
};
