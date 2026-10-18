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

        $indexes = $this->modelListIndexNames();

        if (in_array('model_lists_model_name_unique', $indexes, true)) {
            Schema::table('model_lists', function (Blueprint $table) {
                $table->dropUnique('model_lists_model_name_unique');
            });
        }

        $indexes = $this->modelListIndexNames();
        if (in_array('model_lists_brand_model_name_unique', $indexes, true)) {
            Schema::table('model_lists', function (Blueprint $table) {
                $table->dropUnique('model_lists_brand_model_name_unique');
            });
        }

        if (Schema::hasColumn('model_lists', 'brand')) {
            Schema::table('model_lists', function (Blueprint $table) {
                $table->unique(['brand', 'model_name'], 'model_lists_brand_model_name_unique');
            });
        }
    }

    /**
     * Return model_lists index names without assuming a MySQL-only grammar.
     *
     * MySQL keeps the original SHOW INDEX path used by production, while SQLite
     * tests use Laravel's schema inspector so in-memory migrations do not issue
     * unsupported SHOW INDEX statements.
     *
     * @return array<int, string>
     */
    private function modelListIndexNames(): array
    {
        if (DB::getDriverName() === 'mysql') {
            return collect(DB::select("SHOW INDEX FROM model_lists"))
                ->pluck('Key_name')
                ->unique()
                ->values()
                ->all();
        }

        return collect(Schema::getIndexes('model_lists'))
            ->pluck('name')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function down(): void
    {
        if (!Schema::hasTable('model_lists')) {
            return;
        }

        $indexes = $this->modelListIndexNames();
        if (in_array('model_lists_brand_model_name_unique', $indexes, true)) {
            Schema::table('model_lists', function (Blueprint $table) {
                $table->dropUnique('model_lists_brand_model_name_unique');
            });
        }

        $indexes = $this->modelListIndexNames();
        if (!in_array('model_lists_model_name_unique', $indexes, true)) {
            Schema::table('model_lists', function (Blueprint $table) {
                $table->unique('model_name', 'model_lists_model_name_unique');
            });
        }
    }
};
