<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            if (!Schema::hasColumn('invoice_payments', 'customer_id')) {
                $table->foreignId('customer_id')->nullable()->after('invoice_id')->constrained('customers')->nullOnDelete();
            }

            if (!Schema::hasColumn('invoice_payments', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('customer_id')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            if (Schema::hasColumn('invoice_payments', 'created_by')) {
                $table->dropConstrainedForeignId('created_by');
            }

            if (Schema::hasColumn('invoice_payments', 'customer_id')) {
                $table->dropConstrainedForeignId('customer_id');
            }
        });
    }
};
