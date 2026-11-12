<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_period_id')->constrained('commission_periods')->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('invoice_number_snapshot');
            $table->dateTime('invoice_date_snapshot');
            $table->string('product_name_snapshot');
            $table->string('variant_name_snapshot')->nullable();
            $table->unsignedInteger('quantity_snapshot');
            $table->unsignedBigInteger('gross_amount_snapshot');
            $table->unsignedBigInteger('discount_amount_snapshot');
            $table->unsignedBigInteger('net_amount_snapshot');
            $table->decimal('base_rate_snapshot', 7, 4);
            $table->decimal('campaign_rate_snapshot', 7, 4)->default(0);
            $table->decimal('effective_rate_snapshot', 7, 4);
            $table->unsignedBigInteger('base_commission_amount');
            $table->unsignedBigInteger('campaign_commission_amount');
            $table->unsignedBigInteger('total_commission_amount');
            $table->foreignId('rate_rule_id')->nullable()->constrained('commission_rate_revisions')->nullOnDelete();
            $table->string('rate_source_type', 20)->nullable();
            $table->unsignedBigInteger('rate_source_id')->nullable();
            $table->foreignId('campaign_id')->nullable()->constrained('commission_campaigns')->nullOnDelete();
            $table->string('campaign_name_snapshot')->nullable();
            $table->boolean('missing_rate')->default(false);
            $table->unsignedSmallInteger('calculation_version')->default(1);
            $table->char('calculation_fingerprint', 64);
            $table->string('status', 20)->default('active');
            $table->unsignedTinyInteger('active_marker')->nullable();
            $table->timestamp('calculated_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['commission_period_id', 'invoice_item_id', 'active_marker'], 'commission_ledger_one_active');
            $table->index(['commission_period_id', 'status', 'seller_id'], 'commission_ledger_period_seller');
            $table->index(['invoice_id', 'status'], 'commission_ledger_invoice');
            $table->index(['product_id', 'product_variant_id'], 'commission_ledger_product_variant');
        });

        Schema::create('commission_calculation_warnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_period_id')->constrained('commission_periods')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('message');
            $table->json('context')->nullable();
            $table->timestamps();
            $table->index(['commission_period_id', 'code'], 'commission_warning_period_code');
        });

        foreach ([
            ['key' => 'commissions.recalculate', 'name' => 'به‌روزرسانی محاسبات پورسانت'],
            ['key' => 'commissions.view_seller_details', 'name' => 'مشاهده جزئیات پورسانت فروشنده'],
        ] as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $permission['key']],
                $permission + ['group' => 'پورسانت فروشندگان', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_calculation_warnings');
        Schema::dropIfExists('commission_ledger_entries');
        DB::table('permissions')->whereIn('key', ['commissions.recalculate', 'commissions.view_seller_details'])->delete();
    }
};
