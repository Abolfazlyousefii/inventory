<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_documents', function (Blueprint $table) {
            $table->id();
            $table->string('document_number')->nullable()->unique();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('commission_period_id')->constrained('commission_periods')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->boolean('needs_recalculation')->default(false);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['seller_id', 'commission_period_id'], 'commission_document_seller_period_unique');
            $table->index(['commission_period_id', 'status'], 'commission_document_period_status');
        });

        Schema::create('commission_document_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_document_id')->constrained('commission_documents')->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('active_invoice_id')->nullable();
            $table->string('invoice_number_snapshot');
            $table->dateTime('invoice_date_snapshot');
            $table->string('customer_name_snapshot');
            $table->unsignedBigInteger('seller_id_snapshot');
            $table->foreignId('source_period_id')->constrained('commission_periods')->restrictOnDelete();
            $table->unsignedBigInteger('net_sales_snapshot');
            $table->unsignedBigInteger('base_commission_snapshot');
            $table->unsignedBigInteger('campaign_commission_snapshot');
            $table->unsignedBigInteger('total_commission_snapshot');
            $table->unsignedInteger('ledger_entry_count');
            $table->unsignedSmallInteger('calculation_version');
            $table->char('source_fingerprint', 64);
            $table->boolean('is_outside_period')->default(false);
            $table->text('outside_period_reason')->nullable();
            $table->string('status', 20)->default('pending');
            $table->boolean('is_stale')->default(false);
            $table->foreignId('added_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('added_at');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->text('removal_reason')->nullable();
            $table->timestamps();
            $table->unique(['commission_document_id', 'invoice_id'], 'commission_document_invoice_history_unique');
            $table->unique('active_invoice_id', 'commission_document_active_invoice_unique');
            $table->index(['commission_document_id', 'status'], 'commission_document_item_status');
            $table->index(['source_period_id', 'is_stale'], 'commission_document_item_source');
            $table->foreign('active_invoice_id')->references('id')->on('invoices')->nullOnDelete();
        });

        Schema::create('commission_document_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('commission_document_id')->constrained('commission_documents')->restrictOnDelete();
            $table->foreignId('commission_document_item_id')->nullable()->constrained('commission_document_items')->nullOnDelete();
            $table->string('event_type', 60);
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');
            $table->index(['commission_document_id', 'created_at'], 'commission_document_event_history');
        });

        foreach ([
            ['key' => 'commissions.manage_documents', 'name' => 'مدیریت اسناد پورسانت'],
            ['key' => 'commissions.review_documents', 'name' => 'بررسی مالی اسناد پورسانت'],
            ['key' => 'commissions.print_documents', 'name' => 'چاپ اسناد پورسانت'],
        ] as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $permission['key']],
                $permission + ['group' => 'پورسانت فروشندگان', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_document_events');
        Schema::dropIfExists('commission_document_items');
        Schema::dropIfExists('commission_documents');
        DB::table('permissions')->whereIn('key', ['commissions.manage_documents', 'commissions.review_documents', 'commissions.print_documents'])->delete();
    }
};
