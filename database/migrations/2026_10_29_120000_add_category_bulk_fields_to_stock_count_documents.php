<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stock_count_documents', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->index();
            $table->uuid('request_key')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('stock_count_documents', function (Blueprint $table) {
            $table->dropUnique(['request_key']);
            $table->dropIndex(['category_id']);
            $table->dropColumn(['request_key','category_id']);
        });
    }
};
