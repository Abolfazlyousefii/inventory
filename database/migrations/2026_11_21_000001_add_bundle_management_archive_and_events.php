<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->timestamp('bundle_archived_at')->nullable()->index();
        });
        Schema::create('bundle_management_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->unsignedBigInteger('bundle_variant_id')->index();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 32);
            $table->string('reason', 500);
            $table->json('details');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive: historical audit events are never dropped on rollback.
    }
};
