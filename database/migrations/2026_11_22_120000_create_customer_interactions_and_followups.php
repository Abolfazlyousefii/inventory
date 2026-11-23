<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_interactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->uuid('request_key')->unique();
            $t->string('request_hash', 64);
            $t->string('kind', 20);
            $t->text('body');
            $t->timestamps();
            $t->string('legacy_source', 40)->nullable();
            $t->string('legacy_id', 100)->nullable();
            $t->unique(['legacy_source', 'legacy_id']);
            $t->index(['customer_id', 'id']);
        });
        Schema::create('customer_followups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('interaction_id')->constrained('customer_interactions')->restrictOnDelete();
            $t->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $t->string('title', 200);
            $t->dateTime('due_at');
            $t->string('status', 20)->default('open');
            $t->unsignedInteger('version')->default(1);
            $t->text('result')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->dateTime('closed_at')->nullable();
            $t->dateTime('reminded_at')->nullable();
            $t->timestamps();
            $t->string('legacy_source', 40)->nullable();
            $t->string('legacy_id', 100)->nullable();
            $t->unique(['legacy_source', 'legacy_id']);
            $t->index(['assigned_to', 'status', 'due_at']);
            $t->index(['customer_id', 'status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_followups');
        Schema::dropIfExists('customer_interactions');
    }
};
