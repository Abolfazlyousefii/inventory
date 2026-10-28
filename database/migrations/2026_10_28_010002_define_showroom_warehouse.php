<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('warehouses')->where('type', 'showroom')->exists()) {
            DB::table('warehouses')->insert(['name' => 'انبار شوروم', 'type' => 'showroom', 'is_active' => true, 'parent_id' => null, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    { /* Preserve warehouse identity, stock and document references. */
    }
};
