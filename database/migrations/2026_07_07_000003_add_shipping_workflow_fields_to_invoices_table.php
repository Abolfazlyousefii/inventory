<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'shipping_status')) {
                $table->string('shipping_status', 30)->nullable()->index()->after('status_changed_by');
            }
            if (! Schema::hasColumn('invoices', 'shipped_at')) {
                $table->timestamp('shipped_at')->nullable()->after('shipping_status');
            }
            if (! Schema::hasColumn('invoices', 'shipped_by')) {
                $table->foreignId('shipped_by')->nullable()->after('shipped_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('invoices', 'shipping_note')) {
                $table->text('shipping_note')->nullable()->after('shipped_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'shipped_by')) {
                $table->dropConstrainedForeignId('shipped_by');
            }

            foreach (['shipping_note', 'shipped_at', 'shipping_status'] as $column) {
                if (Schema::hasColumn('invoices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
