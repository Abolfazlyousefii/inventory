<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('invoices', 'stock_deduction_status')) {
            Schema::table('invoices', fn (Blueprint $table) => $table->string('stock_deduction_status', 24)->nullable());
        }

        // Audited historical exception: order-89 unpaid-queue V2 deliberately skipped
        // ALL initial stock deductions. Identify the exact document, not arbitrary
        // expired holds (ordinary unheld imports can legitimately deduct stock).
        // Metadata only: never rewrite a receipt, movement, balance or quantity.
        if (Schema::hasTable('site_stock_holds') && DB::table('site_stock_holds')
            ->where('id', 31)->where('site_order_id', 89)->where('status', 'expired')
            ->whereNull('consumed_at')->whereNotNull('released_at')->exists()) {
            DB::table('invoices')->where('id', 2461)->where('uuid', '02211')
                ->where('external_order_id', 89)->where('created_at', '2026-10-28 14:41:16')
                ->whereNull('stock_deduction_status')
                ->update(['stock_deduction_status' => 'not_deducted']);
        }
    }

    public function down(): void
    {
        // Removing this metadata would re-enable unsafe historical returns.
        // Keep the additive safety column on rollback.
    }
};
