<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('product_deactivation_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('product_deactivation_documents', 'items_count')) {
                $table->unsignedInteger('items_count')->default(1)->after('variant_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_deactivation_documents', function (Blueprint $table) {
            if (Schema::hasColumn('product_deactivation_documents', 'items_count')) {
                $table->dropColumn('items_count');
            }
        });
    }
};
