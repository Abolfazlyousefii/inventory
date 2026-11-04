<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stock_count_documents', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('scope_name')->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('stock_count_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn('scope_name');
        });
    }
};
