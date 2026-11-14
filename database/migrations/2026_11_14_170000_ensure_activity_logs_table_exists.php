<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Some local imports exclude audit history and its table entirely.
        // Never replace existing logs: their IDs can be financial references.
        if (Schema::hasTable('activity_logs')) {
            foreach (['id', 'user_id', 'action', 'subject_type', 'subject_id', 'description', 'properties', 'occurred_at', 'created_at', 'updated_at'] as $column) {
                if (! Schema::hasColumn('activity_logs', $column)) {
                    throw new RuntimeException('Existing activity log schema needs review; no history was changed.');
                }
            }

            return;
        }

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 100);
            $table->nullableMorphs('subject');
            $table->text('description');
            $table->json('properties')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Preserve audit records and financial source references on rollback.
    }
};
