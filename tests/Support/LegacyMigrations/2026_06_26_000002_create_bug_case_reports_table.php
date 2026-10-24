<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bug_case_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bug_case_id')->constrained('bug_cases')->cascadeOnDelete();
            $table->text('summary')->nullable();
            $table->longText('raw_report')->nullable();
            $table->longText('codex_prompt')->nullable();
            $table->json('findings')->nullable();
            $table->json('suspected_files')->nullable();
            $table->json('broken_rules')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bug_case_reports');
    }
};
