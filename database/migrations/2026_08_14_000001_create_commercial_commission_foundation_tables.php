<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rate_revisions', function (Blueprint $table) {
            $table->id();
            $table->string('target_type', 20);
            $table->unsignedBigInteger('target_id');
            $table->string('target_key', 64);
            $table->unsignedTinyInteger('active_marker')->nullable();
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->decimal('percentage', 7, 4);
            $table->dateTime('effective_from');
            $table->dateTime('effective_to')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['target_key', 'active_marker'], 'commission_rate_one_active');
            $table->index(['target_type', 'target_id', 'effective_from'], 'commission_rate_lookup');
        });

        Schema::create('commission_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('bonus_percentage', 7, 4);
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->text('notes')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['archived_at', 'start_at', 'end_at'], 'commission_campaign_period');
        });

        Schema::create('commission_campaign_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_campaign_id')->constrained()->cascadeOnDelete();
            $table->string('target_type', 20);
            $table->unsignedBigInteger('target_id');
            $table->string('target_key', 64);
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['commission_campaign_id', 'target_key'], 'commission_campaign_target_unique');
            $table->index(['target_type', 'target_id'], 'commission_campaign_target_lookup');
        });

        Schema::create('commission_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('cycle_day')->default(10);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('commission_periods', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->unsignedTinyInteger('cycle_day_snapshot');
            $table->string('status', 20)->default('open');
            $table->boolean('needs_recalculation')->default(false);
            $table->timestamps();
            $table->unique(['start_at', 'end_at'], 'commission_period_range_unique');
            $table->index(['status', 'start_at', 'end_at']);
        });

        foreach ([
            ['key' => 'page.commercial.commissions', 'name' => 'پورسانت فروشندگان', 'group' => 'بازرگانی و فروش'],
            ['key' => 'commissions.manage_rates', 'name' => 'مدیریت نرخ‌های پورسانت', 'group' => 'پورسانت فروشندگان'],
            ['key' => 'commissions.manage_campaigns', 'name' => 'مدیریت کمپین‌های پورسانت', 'group' => 'پورسانت فروشندگان'],
            ['key' => 'commissions.manage_periods', 'name' => 'مدیریت دوره‌های پورسانت', 'group' => 'پورسانت فروشندگان'],
        ] as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $permission['key']],
                $permission + ['guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_periods');
        Schema::dropIfExists('commission_settings');
        Schema::dropIfExists('commission_campaign_targets');
        Schema::dropIfExists('commission_campaigns');
        Schema::dropIfExists('commission_rate_revisions');
        DB::table('permissions')->whereIn('key', ['page.commercial.commissions', 'commissions.manage_rates', 'commissions.manage_campaigns', 'commissions.manage_periods'])->delete();
    }
};
