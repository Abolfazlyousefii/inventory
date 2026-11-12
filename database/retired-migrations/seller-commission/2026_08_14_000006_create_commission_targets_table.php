<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('commission_period_id')->constrained('commission_periods')->restrictOnDelete();
            $table->unsignedBigInteger('target_amount');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['seller_id', 'commission_period_id'], 'commission_target_seller_period_unique');
            $table->index(['commission_period_id', 'target_amount'], 'commission_target_period_amount');
        });

        DB::table('permissions')->updateOrInsert(
            ['key' => 'commissions.manage_targets'],
            [
                'name' => 'مدیریت تارگت‌های پورسانت',
                'group' => 'پورسانت فروشندگان',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_targets');
        DB::table('permissions')->where('key', 'commissions.manage_targets')->delete();
    }
};
