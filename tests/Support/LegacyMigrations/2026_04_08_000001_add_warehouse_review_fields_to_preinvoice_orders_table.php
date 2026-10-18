<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('preinvoice_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('preinvoice_orders', 'warehouse_review_note')) {
                $table->text('warehouse_review_note')->nullable()->after('total_price');
            }
            if (!Schema::hasColumn('preinvoice_orders', 'warehouse_reject_reason')) {
                $table->text('warehouse_reject_reason')->nullable()->after('warehouse_review_note');
            }
            if (!Schema::hasColumn('preinvoice_orders', 'warehouse_reviewed_by')) {
                $table->foreignId('warehouse_reviewed_by')->nullable()->after('warehouse_reject_reason')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('preinvoice_orders', 'warehouse_reviewed_at')) {
                $table->timestamp('warehouse_reviewed_at')->nullable()->after('warehouse_reviewed_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('preinvoice_orders', function (Blueprint $table) {
            if (Schema::hasColumn('preinvoice_orders', 'warehouse_reviewed_by')) {
                $table->dropConstrainedForeignId('warehouse_reviewed_by');
            }
            $table->dropColumn(['warehouse_reviewed_at', 'warehouse_reject_reason', 'warehouse_review_note']);
        });
    }
};
