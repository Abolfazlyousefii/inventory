<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('preinvoice_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('preinvoice_orders', 'uuid')) {
                $table->uuid('uuid')->nullable()->unique()->after('id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('preinvoice_orders', function (Blueprint $table) {
            if (Schema::hasColumn('preinvoice_orders', 'uuid')) {
                $table->dropUnique(['uuid']);
                $table->dropColumn('uuid');
            }
        });
    }
};
