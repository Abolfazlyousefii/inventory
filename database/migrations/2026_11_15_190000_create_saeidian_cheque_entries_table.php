<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saeidian_cheque_entries', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 16);
            $t->foreignId('cheque_id')->nullable()->unique()->constrained('cheques')->restrictOnDelete();
            $t->unsignedBigInteger('amount');
            $t->date('effective_date');
            $t->string('cheque_number')->nullable();
            $t->string('party');
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['effective_date', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saeidian_cheque_entries');
    }
};
