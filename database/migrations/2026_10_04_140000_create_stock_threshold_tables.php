<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_threshold_rules', function (Blueprint $table) {
            $table->id();
            $table->string('target_type', 16);
            $table->unsignedBigInteger('target_id');
            $table->string('measure', 16);
            $table->unsignedInteger('minimum');
            $table->timestamps();
            $table->unique(['target_type', 'target_id', 'measure'], 'stock_threshold_target_unique');
        });
        Schema::create('stock_threshold_alert_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('alert_date');
            $table->timestamps();
            $table->unique(['user_id', 'alert_date']);
        });
        \Illuminate\Support\Facades\DB::table('permissions')->updateOrInsert(
            ['key' => 'page.warehouse.thresholds'],
            ['name' => 'آستانه موجودی', 'group' => 'انبارداری', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()],
        );
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_threshold_alert_days');
        Schema::dropIfExists('stock_threshold_rules');
    }
};
