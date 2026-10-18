<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('code', 4)->nullable()->unique()->after('name');
        });

        Schema::table('model_lists', function (Blueprint $table) {
            $table->string('code', 4)->nullable()->unique()->after('model_name');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->foreignId('model_list_id')->nullable()->constrained('model_lists')->nullOnDelete()->after('product_id');
            $table->string('variety_name')->nullable()->after('variant_name');
            $table->string('variety_code', 4)->nullable()->after('variety_name');
            $table->string('variant_code', 12)->nullable()->unique()->after('variety_code');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('model_list_id');
            $table->dropUnique('product_variants_variant_code_unique');
            $table->dropColumn(['variety_name', 'variety_code', 'variant_code']);
        });

        Schema::table('model_lists', function (Blueprint $table) {
            $table->dropUnique('model_lists_code_unique');
            $table->dropColumn('code');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique('categories_code_unique');
            $table->dropColumn('code');
        });
    }
};
