<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('model_lists', 'brand')) {
            Schema::table('model_lists', function (Blueprint $table) {
                $table->string('brand', 100)->nullable()->after('id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('model_lists', 'brand')) {
            Schema::table('model_lists', function (Blueprint $table) {
                $table->dropIndex(['brand']);
                $table->dropColumn('brand');
            });
        }
    }
};
