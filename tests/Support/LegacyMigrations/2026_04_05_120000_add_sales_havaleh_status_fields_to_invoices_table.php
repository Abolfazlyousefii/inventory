<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'status_changed_at')) {
                $table->timestamp('status_changed_at')->nullable()->after('status');
            }
            if (!Schema::hasColumn('invoices', 'status_changed_by')) {
                $table->foreignId('status_changed_by')->nullable()->after('status_changed_at')->constrained('users')->nullOnDelete();
            }
        });

        DB::table('invoices')->where('status', 'warehouse_pending')->update(['status' => 'pending_warehouse_approval']);
        DB::table('invoices')->where('status', 'warehouse_collecting')->update(['status' => 'collecting']);
        DB::table('invoices')->where('status', 'warehouse_checking')->update(['status' => 'checking_discrepancy']);
        DB::table('invoices')->where('status', 'warehouse_packing')->update(['status' => 'packing']);
        DB::table('invoices')->where('status', 'warehouse_sent')->update(['status' => 'shipped']);
        DB::table('invoices')->where('status', 'canceled')->update(['status' => 'not_shipped']);
    }

    public function down(): void
    {
        DB::table('invoices')->where('status', 'pending_warehouse_approval')->update(['status' => 'warehouse_pending']);
        DB::table('invoices')->where('status', 'collecting')->update(['status' => 'warehouse_collecting']);
        DB::table('invoices')->where('status', 'checking_discrepancy')->update(['status' => 'warehouse_checking']);
        DB::table('invoices')->where('status', 'final_check')->update(['status' => 'warehouse_checking']);
        DB::table('invoices')->where('status', 'packing')->update(['status' => 'warehouse_packing']);
        DB::table('invoices')->where('status', 'shipped')->update(['status' => 'warehouse_sent']);
        DB::table('invoices')->where('status', 'not_shipped')->update(['status' => 'canceled']);

        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'status_changed_by')) {
                $table->dropConstrainedForeignId('status_changed_by');
            }
            if (Schema::hasColumn('invoices', 'status_changed_at')) {
                $table->dropColumn('status_changed_at');
            }
        });
    }
};
