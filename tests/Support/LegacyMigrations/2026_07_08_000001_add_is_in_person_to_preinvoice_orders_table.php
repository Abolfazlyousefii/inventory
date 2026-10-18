<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('preinvoice_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('preinvoice_orders', 'is_in_person')) {
                $table->boolean('is_in_person')->default(false)->after('customer_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('preinvoice_orders', function (Blueprint $table) {
            if (Schema::hasColumn('preinvoice_orders', 'is_in_person')) {
                $table->dropColumn('is_in_person');
            }
        });
    }
};
