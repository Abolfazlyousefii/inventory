<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stock_count_documents', function (Blueprint $table) {
            $table->foreignId('superseded_by_id')->nullable()->constrained('stock_count_documents')->restrictOnDelete();
        });
    }
    public function down(): void
    {
        Schema::table('stock_count_documents', fn (Blueprint $table) => $table->dropConstrainedForeignId('superseded_by_id'));
    }
};
