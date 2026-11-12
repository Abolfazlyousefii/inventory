<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_return_documents', function (Blueprint $table) {
            $table->string('commission_effect_type', 20)->nullable()->after('return_reason')->index('srd_commission_effect_idx');
        });

        Schema::create('commission_correction_entries', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 50);
            $table->string('identity_key', 191)->unique('cc_entries_identity_uq');
            $table->foreignId('commission_period_id')->nullable();
            $table->foreign('commission_period_id', 'cc_entries_period_fk')
                ->references('id')->on('commission_periods')->restrictOnDelete();
            $table->foreignId('source_period_id')->nullable();
            $table->foreign('source_period_id', 'cc_entries_source_period_fk')
                ->references('id')->on('commission_periods')->restrictOnDelete();
            $table->foreignId('seller_id');
            $table->foreign('seller_id', 'cc_entries_seller_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreignId('source_seller_id')->nullable();
            $table->foreign('source_seller_id', 'cc_entries_source_seller_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreignId('target_seller_id')->nullable();
            $table->foreign('target_seller_id', 'cc_entries_target_seller_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable();
            $table->foreign('invoice_id', 'cc_entries_invoice_fk')
                ->references('id')->on('invoices')->nullOnDelete();
            $table->foreignId('invoice_item_id')->nullable();
            $table->foreign('invoice_item_id', 'cc_entries_invoice_item_fk')
                ->references('id')->on('invoice_items')->nullOnDelete();
            $table->foreignId('source_ledger_entry_id')->nullable();
            $table->foreign('source_ledger_entry_id', 'cc_entries_source_ledger_fk')
                ->references('id')->on('commission_ledger_entries')->nullOnDelete();
            $table->foreignId('sales_return_document_id')->nullable();
            $table->foreign('sales_return_document_id', 'cc_entries_return_doc_fk')
                ->references('id')->on('sales_return_documents')->nullOnDelete();
            $table->foreignId('sales_return_item_id')->nullable();
            $table->foreign('sales_return_item_id', 'cc_entries_return_item_fk')
                ->references('id')->on('sales_return_document_items')->nullOnDelete();
            $table->foreignId('seller_reassignment_audit_id')->nullable();
            $table->foreign('seller_reassignment_audit_id', 'cc_entries_reassign_audit_fk')
                ->references('id')->on('seller_reassignment_audits')->nullOnDelete();
            $table->integer('quantity_delta')->default(0);
            $table->bigInteger('net_amount');
            $table->bigInteger('base_commission_amount');
            $table->bigInteger('campaign_commission_amount');
            $table->bigInteger('total_commission_amount');
            $table->string('status', 30)->default('assigned');
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreign('created_by', 'cc_entries_created_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['commission_period_id', 'seller_id', 'event_type'], 'commission_correction_period_seller');
            $table->index(['sales_return_document_id', 'invoice_item_id'], 'commission_correction_return_lineage');
            $table->index(['invoice_id', 'event_type'], 'commission_correction_invoice_event');
            $table->index(['status', 'created_at'], 'commission_correction_queue');
        });

        Schema::create('commission_reconciliation_warnings', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60);
            $table->string('identity_key', 191)->unique('cr_warnings_identity_uq');
            $table->foreignId('commission_period_id')->nullable();
            $table->foreign('commission_period_id', 'cr_warnings_period_fk')
                ->references('id')->on('commission_periods')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable();
            $table->foreign('invoice_id', 'cr_warnings_invoice_fk')
                ->references('id')->on('invoices')->nullOnDelete();
            $table->foreignId('sales_return_document_id')->nullable();
            $table->foreign('sales_return_document_id', 'cr_warnings_return_doc_fk')
                ->references('id')->on('sales_return_documents')->nullOnDelete();
            $table->foreignId('seller_reassignment_audit_id')->nullable();
            $table->foreign('seller_reassignment_audit_id', 'cr_warnings_reassign_audit_fk')
                ->references('id')->on('seller_reassignment_audits')->nullOnDelete();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['code', 'resolved_at'], 'commission_reconciliation_warning_status');
        });

        Schema::create('commission_document_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_document_id');
            $table->foreign('commission_document_id', 'cd_corrections_document_fk')
                ->references('id')->on('commission_documents')->restrictOnDelete();
            $table->foreignId('commission_correction_entry_id')->nullable();
            $table->foreign('commission_correction_entry_id', 'cd_corrections_entry_fk')
                ->references('id')->on('commission_correction_entries')->nullOnDelete();
            $table->unsignedBigInteger('active_correction_entry_id')->nullable();
            $table->string('type', 50);
            $table->string('description');
            $table->string('source_invoice_number')->nullable();
            $table->string('source_period_label')->nullable();
            $table->bigInteger('base_amount');
            $table->bigInteger('campaign_amount');
            $table->bigInteger('total_amount');
            $table->char('source_fingerprint', 64);
            $table->string('status', 20)->default('pending');
            $table->boolean('is_stale')->default(false);
            $table->foreignId('added_by')->nullable();
            $table->foreign('added_by', 'cd_corrections_added_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->timestamp('added_at');
            $table->foreignId('approved_by')->nullable();
            $table->foreign('approved_by', 'cd_corrections_approved_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable();
            $table->foreign('rejected_by', 'cd_corrections_rejected_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->unique(['commission_document_id', 'commission_correction_entry_id'], 'commission_document_correction_history');
            $table->unique('active_correction_entry_id', 'commission_document_active_correction');
            $table->index(['commission_document_id', 'status'], 'commission_document_correction_status');
            $table->foreign('active_correction_entry_id', 'cd_corrections_active_entry_fk')
                ->references('id')->on('commission_correction_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_document_corrections');
        Schema::dropIfExists('commission_reconciliation_warnings');
        Schema::dropIfExists('commission_correction_entries');
        Schema::table('sales_return_documents', fn (Blueprint $table) => $table->dropColumn('commission_effect_type'));
    }
};
