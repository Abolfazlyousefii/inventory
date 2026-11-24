<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnel_entries', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('submission_key')->unique();
            $table->string('title', 255)->nullable();
            $table->text('description')->nullable();
            $table->json('payload')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('submitted_at')->nullable();
            $table->string('source', 32)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();
            $table->index(['kind', 'user_id', 'status']);
            $table->index(['kind', 'start_date', 'end_date']);
            $table->unique(['kind', 'source', 'source_id']);
        });
        Schema::create('personnel_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personnel_entry_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('stage', 32);
            $table->foreignId('approver_id')->constrained('users')->restrictOnDelete();
            $table->string('approver_name');
            $table->string('status', 24);
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['personnel_entry_id', 'sequence']);
            $table->index(['approver_id', 'status']);
        });
        Schema::create('personnel_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personnel_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 32);
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('personnel_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personnel_entry_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('name');
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personnel_attachments');
        Schema::dropIfExists('personnel_events');
        Schema::dropIfExists('personnel_steps');
        Schema::dropIfExists('personnel_entries');
    }
};
