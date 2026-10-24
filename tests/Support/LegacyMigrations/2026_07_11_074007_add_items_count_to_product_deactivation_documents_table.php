<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            Schema::hasTable('product_deactivation_documents') &&
            ! Schema::hasColumn('product_deactivation_documents', 'items_count')
        ) {
            Schema::table('product_deactivation_documents', function (Blueprint $table) {
                $table->unsignedInteger('items_count')
                    ->default(0)
                    ->after('variant_id');
            });
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('product_deactivation_documents') &&
            Schema::hasColumn('product_deactivation_documents', 'items_count')
        ) {
            Schema::table('product_deactivation_documents', function (Blueprint $table) {
                $table->dropColumn('items_count');
            });
        }
    }
};
