<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('inventory_count_freezes')->insertOrIgnore(['id' => 1, 'enabled' => false, 'all_products' => false, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('inventory_count_freezes')->where('id', 1)->delete();
    }
};
