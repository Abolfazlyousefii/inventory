<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('model_lists')) {
            return;
        }

        if (Schema::hasColumn('model_lists', 'brand')) {
            DB::table('model_lists')
                ->whereNotNull('brand')
                ->where('brand', '<>', '')
                ->select(['id', 'brand', 'model_name'])
                ->orderBy('id')
                ->chunkById(500, function ($rows): void {
                    foreach ($rows as $row) {
                        DB::table('model_lists')
                            ->where('id', $row->id)
                            ->update([
                                'model_name' => trim(trim((string) $row->brand).' '.trim((string) $row->model_name)),
                            ]);
                    }
                });

            Schema::table('model_lists', function (Blueprint $table) {
                $table->dropUnique('model_lists_brand_model_name_unique');
                $table->dropIndex('model_lists_brand_index');
            });

            Schema::table('model_lists', function (Blueprint $table) {
                $table->dropColumn('brand');
            });
        }

        Schema::table('model_lists', function (Blueprint $table) {
            $table->unique('model_name', 'model_lists_model_name_unique');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('model_lists')) {
            return;
        }

        if (!Schema::hasColumn('model_lists', 'brand')) {
            Schema::table('model_lists', function (Blueprint $table) {
                $table->string('brand', 100)->default('')->after('id');
            });
        }

        Schema::table('model_lists', function (Blueprint $table) {
            $table->dropUnique('model_lists_model_name_unique');
            $table->unique(['brand', 'model_name']);
            $table->index('brand');
        });
    }
};
