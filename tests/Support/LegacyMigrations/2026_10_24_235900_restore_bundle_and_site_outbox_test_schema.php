<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function guardTestDatabase(): void
    {
        if (
            ! app()->environment('testing')
            || DB::connection()->getDriverName() !== 'sqlite'
            || DB::connection()->getDatabaseName() !== ':memory:'
        ) {
            throw new RuntimeException(
                'This migration is only for the in-memory test database.'
            );
        }
    }

    public function up(): void
    {
        $this->guardTestDatabase();

        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'is_bundle')) $table->boolean('is_bundle')->default(false);
            if (! Schema::hasColumn('products', 'bundle_stock_mode')) $table->string('bundle_stock_mode', 16)->default('physical');
            if (! Schema::hasColumn('products', 'bundle_quantity_mode')) $table->string('bundle_quantity_mode', 16)->default('custom');
            if (! Schema::hasColumn('products', 'bundle_shared_remaining')) $table->unsignedInteger('bundle_shared_remaining')->nullable();
            if (! Schema::hasColumn('products', 'bundle_discount_type')) $table->string('bundle_discount_type', 16)->nullable();
            if (! Schema::hasColumn('products', 'bundle_discount_value')) $table->unsignedBigInteger('bundle_discount_value')->nullable();
            if (! Schema::hasColumn('products', 'bundle_site_auto_publish')) $table->boolean('bundle_site_auto_publish')->nullable();

            $table->index('is_bundle', 'products_is_bundle_index');
        });

        Schema::create('site_stock_sync_outbox', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id')->default(0);
            $table->string('origin', 16)->default('inventory');
            $table->dateTime('created_at');
            $table->dateTime('available_at');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('claimed_by', 36)->nullable();
            $table->dateTime('locked_until')->nullable();
            $table->string('last_error', 500)->nullable();

            $table->index(
                ['available_at', 'id'],
                'site_stock_outbox_due_idx'
            );
        });
    }

    public function down(): void
    {
        $this->guardTestDatabase();

        Schema::dropIfExists('site_stock_sync_outbox');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_is_bundle_index');
            $table->dropColumn([
                'is_bundle',
                'bundle_stock_mode',
                'bundle_quantity_mode',
                'bundle_shared_remaining',
                'bundle_discount_type',
                'bundle_discount_value',
                'bundle_site_auto_publish',
            ]);
        });
    }
};