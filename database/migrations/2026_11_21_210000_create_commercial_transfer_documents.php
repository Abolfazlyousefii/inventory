<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_transfer_sequences', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->unsignedBigInteger('next_number');
        });
        DB::table('commercial_transfer_sequences')->insert(['key' => 'transfer', 'next_number' => 500001]);
        Schema::create('commercial_transfer_documents', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('number')->unique();
            $t->uuid('submission_key')->unique();
            $t->string('legacy_key')->nullable()->unique();
            $t->string('kind', 20);
            $t->string('status', 20)->default('draft');
            $t->foreignId('from_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('to_id')->constrained('users')->restrictOnDelete();
            $t->string('from_name');
            $t->string('to_name');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('actor_name');
            $t->text('reason');
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('registered_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
            $t->index(['kind', 'status', 'id']);
        });
        Schema::create('commercial_transfer_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('commercial_transfer_documents')->cascadeOnDelete();
            $t->unsignedBigInteger('target_id');
            $t->string('label');
            $t->string('token', 64);
            $t->json('before');
            $t->json('after')->nullable();
            $t->unique(['document_id', 'target_id']);
            $t->index('target_id');
        });
        Schema::create('commercial_transfer_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('commercial_transfer_documents')->cascadeOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('actor_name');
            $t->string('action');
            $t->text('reason')->nullable();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_transfer_events');
        Schema::dropIfExists('commercial_transfer_items');
        Schema::dropIfExists('commercial_transfer_documents');
        Schema::dropIfExists('commercial_transfer_sequences');
    }
};
