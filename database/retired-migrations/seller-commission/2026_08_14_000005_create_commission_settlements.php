<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_periods', function (Blueprint $table) {
            $table->foreignId('review_started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('review_started_at')->nullable();
            $table->bigInteger('total_net_sales_snapshot')->nullable();
            $table->bigInteger('base_commission_snapshot')->nullable();
            $table->bigInteger('campaign_commission_snapshot')->nullable();
            $table->bigInteger('return_reversal_snapshot')->nullable();
            $table->bigInteger('seller_correction_snapshot')->nullable();
            $table->bigInteger('manual_adjustment_snapshot')->nullable();
            $table->bigInteger('approved_commission_snapshot')->nullable();
            $table->unsignedInteger('seller_count_snapshot')->nullable();
            $table->unsignedInteger('document_count_snapshot')->nullable();
            $table->char('close_fingerprint', 64)->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
        });

        Schema::table('commission_documents', function (Blueprint $table) {
            $table->bigInteger('final_net_sales')->nullable();
            $table->bigInteger('final_base_commission')->nullable();
            $table->bigInteger('final_campaign_commission')->nullable();
            $table->bigInteger('final_correction_amount')->nullable();
            $table->bigInteger('final_adjustment_amount')->nullable();
            $table->bigInteger('final_commission_total')->nullable();
            $table->char('final_fingerprint', 64)->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->index(['commission_period_id', 'status'], 'commission_document_final_status');
        });

        Schema::create('commission_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('commission_period_id')->constrained('commission_periods')->restrictOnDelete();
            $table->foreignId('source_period_id')->nullable()->constrained('commission_periods')->restrictOnDelete();
            $table->string('source_type', 20)->default('manual');
            $table->string('type', 40)->default('manual');
            $table->string('identity_key', 191)->nullable()->unique();
            $table->bigInteger('amount');
            $table->text('reason');
            $table->string('source_reference')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->index(['commission_period_id', 'seller_id', 'status'], 'commission_adjustment_period_seller');
            $table->index(['source_period_id', 'type'], 'commission_adjustment_source');
        });

        Schema::create('commission_document_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_document_id')->constrained('commission_documents')->restrictOnDelete();
            $table->foreignId('commission_adjustment_id')->constrained('commission_adjustments')->restrictOnDelete();
            $table->bigInteger('amount_snapshot');
            $table->string('type_snapshot', 40);
            $table->text('reason_snapshot');
            $table->char('source_fingerprint', 64);
            $table->string('status', 20)->default('pending');
            $table->boolean('is_stale')->default(false);
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('added_at');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->unique(['commission_document_id', 'commission_adjustment_id'], 'commission_document_adjustment_unique');
            $table->index(['commission_document_id', 'status'], 'commission_document_adjustment_status');
        });

        Schema::create('commission_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('settlement_number')->nullable()->unique();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('commission_period_id')->constrained('commission_periods')->restrictOnDelete();
            $table->foreignId('commission_document_id')->constrained('commission_documents')->restrictOnDelete();
            $table->bigInteger('net_sales_snapshot');
            $table->bigInteger('base_commission_snapshot');
            $table->bigInteger('campaign_commission_snapshot');
            $table->bigInteger('return_reversal_snapshot');
            $table->bigInteger('seller_correction_snapshot');
            $table->bigInteger('manual_adjustment_snapshot');
            $table->bigInteger('net_payable');
            $table->bigInteger('paid_amount')->default(0);
            $table->bigInteger('remaining_amount');
            $table->string('status', 24)->default('unpaid');
            $table->boolean('carry_forward_created')->default(false);
            $table->char('source_fingerprint', 64);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at');
            $table->timestamp('fully_paid_at')->nullable();
            $table->timestamps();
            $table->unique(['seller_id', 'commission_period_id'], 'commission_settlement_seller_period_unique');
            $table->index(['commission_period_id', 'status'], 'commission_settlement_period_status');
        });

        Schema::create('commission_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_settlement_id')->constrained('commission_settlements')->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('commission_period_id')->constrained('commission_periods')->restrictOnDelete();
            $table->string('idempotency_key', 191)->nullable()->unique();
            $table->bigInteger('amount');
            $table->dateTime('paid_at');
            $table->string('payment_method', 30)->nullable();
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('recorded');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();
            $table->index(['commission_settlement_id', 'status', 'paid_at'], 'commission_payment_history');
        });

        Schema::create('commission_period_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_period_id')->constrained('commission_periods')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 60);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');
            $table->index(['commission_period_id', 'created_at'], 'commission_period_event_history');
        });

        foreach ([
            ['key' => 'commissions.finalize_documents', 'name' => 'نهایی‌سازی اسناد پورسانت'],
            ['key' => 'commissions.close_periods', 'name' => 'بستن دوره‌های پورسانت'],
            ['key' => 'commissions.manage_adjustments', 'name' => 'ثبت تعدیلات پورسانت'],
            ['key' => 'commissions.review_adjustments', 'name' => 'بررسی تعدیلات پورسانت'],
            ['key' => 'commissions.record_payments', 'name' => 'ثبت پرداخت پورسانت'],
            ['key' => 'commissions.void_payments', 'name' => 'ابطال پرداخت پورسانت'],
            ['key' => 'commissions.mark_period_paid', 'name' => 'پرداخت‌شده کردن دوره پورسانت'],
            ['key' => 'commissions.view_settlements', 'name' => 'مشاهده تسویه‌های پورسانت'],
        ] as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $permission['key']],
                $permission + ['group' => 'پورسانت فروشندگان', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_period_events');
        Schema::dropIfExists('commission_payments');
        Schema::dropIfExists('commission_settlements');
        Schema::dropIfExists('commission_document_adjustments');
        Schema::dropIfExists('commission_adjustments');
        Schema::table('commission_documents', function (Blueprint $table) {
            $table->dropColumn(['final_net_sales', 'final_base_commission', 'final_campaign_commission', 'final_correction_amount', 'final_adjustment_amount', 'final_commission_total', 'final_fingerprint', 'finalized_by', 'finalized_at']);
        });
        Schema::table('commission_periods', function (Blueprint $table) {
            $table->dropColumn(['review_started_by', 'review_started_at', 'total_net_sales_snapshot', 'base_commission_snapshot', 'campaign_commission_snapshot', 'return_reversal_snapshot', 'seller_correction_snapshot', 'manual_adjustment_snapshot', 'approved_commission_snapshot', 'seller_count_snapshot', 'document_count_snapshot', 'close_fingerprint', 'closed_by', 'closed_at', 'paid_by', 'paid_at']);
        });
        DB::table('permissions')->whereIn('key', ['commissions.finalize_documents', 'commissions.close_periods', 'commissions.manage_adjustments', 'commissions.review_adjustments', 'commissions.record_payments', 'commissions.void_payments', 'commissions.mark_period_paid', 'commissions.view_settlements'])->delete();
    }
};
