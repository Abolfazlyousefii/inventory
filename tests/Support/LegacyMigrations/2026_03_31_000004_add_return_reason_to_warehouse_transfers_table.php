<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('warehouse_transfers', function (Blueprint $table) {
            $table->string('return_reason', 50)->nullable()->after('beneficiary_name')->index();
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_transfers', function (Blueprint $table) {
            $table->dropColumn('return_reason');
        });
    }
};
