<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'inventory_to_site_synced')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->boolean('inventory_to_site_synced')->default(false);
            });
        }

        if (! Schema::hasColumn('products', 'site_to_inventory_verified')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->boolean('site_to_inventory_verified')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'inventory_to_site_synced')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn('inventory_to_site_synced');
            });
        }

        if (Schema::hasColumn('products', 'site_to_inventory_verified')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn('site_to_inventory_verified');
            });
        }
    }
};
