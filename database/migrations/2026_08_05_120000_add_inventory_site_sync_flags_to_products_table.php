<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('inventory_to_site_synced')->default(false);
            $table->boolean('site_to_inventory_verified')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['inventory_to_site_synced', 'site_to_inventory_verified']);
        });
    }
};
