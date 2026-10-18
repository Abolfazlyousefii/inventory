<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_sync_states', function (Blueprint $table) {
            $table->id();
            $table->string('integration', 50);
            $table->string('stream', 80);
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_succeeded_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['integration', 'stream']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_sync_states');
    }
};
