<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('id')->constrained('warehouses')->nullOnDelete();
        });

        $personnelWarehouseId = DB::table('warehouses')->insertGetId([
            'name' => 'انبار پرسنل',
            'type' => 'personnel',
            'personnel_name' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('warehouses')
            ->where('type', 'personnel')
            ->where('id', '!=', $personnelWarehouseId)
            ->update(['parent_id' => $personnelWarehouseId]);
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
