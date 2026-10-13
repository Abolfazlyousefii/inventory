<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('permissions', 'key')) {
            throw new RuntimeException('The page permission schema must be installed before the daily warehouse report.');
        }
        Schema::create('warehouse_daily_counts', function (Blueprint $table) {
            $table->id();
            $table->date('review_date');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_id');
            // Zero identifies a product without variants; never an aggregate of its variants.
            $table->unsignedBigInteger('variant_id')->default(0);
            $table->bigInteger('stock_snapshot');
            $table->unsignedInteger('physical_quantity');
            $table->text('note')->nullable();
            $table->string('product_name');
            $table->string('variant_name')->nullable();
            $table->string('code')->nullable();
            $table->unsignedBigInteger('updated_by');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['review_date', 'warehouse_id', 'product_id', 'variant_id'], 'warehouse_daily_count_unique');
        });
        Schema::create('warehouse_daily_count_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('count_id')->constrained('warehouse_daily_counts')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->timestamp('created_at');
        });
        DB::table('permissions')->updateOrInsert(['key' => 'page.warehouse.daily_sales'], [
            'name' => 'گزارش فروش روز', 'group' => 'انبارداری', 'guard_name' => 'web',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Give the new page to existing admin roles only; warehouse roles remain configurable.
        if (Schema::hasTable('roles') && Schema::hasTable('role_has_permissions')) {
            $permission = DB::table('permissions')->where('key', 'page.warehouse.daily_sales')->value('id');
            foreach (DB::table('roles')->where('guard_name', 'web')->whereIn('name', ['Admin', 'admin'])->pluck('id') as $role) {
                DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
            }
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_daily_count_history');
        Schema::dropIfExists('warehouse_daily_counts');
        DB::table('permissions')->where('key', 'page.warehouse.daily_sales')->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
