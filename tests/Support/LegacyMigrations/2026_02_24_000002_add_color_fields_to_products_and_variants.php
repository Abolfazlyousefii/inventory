<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'has_colors')) {
                $table->boolean('has_colors')->default(false)->after('models');
            }
        });

        Schema::table('product_variants', function (Blueprint $table) {
            if (!Schema::hasColumn('product_variants', 'color_id')) {
                $table->foreignId('color_id')->nullable()->constrained('colors')->nullOnDelete()->after('model_list_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            if (Schema::hasColumn('product_variants', 'color_id')) {
                $table->dropConstrainedForeignId('color_id');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'has_colors')) {
                $table->dropColumn('has_colors');
            }
        });
    }
};
