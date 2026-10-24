<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->after('product_id')->constrained('warehouses')->nullOnDelete();
            $table->string('transaction_type')->nullable()->after('reason');
            $table->string('reference_type')->nullable()->after('reference');
            $table->unsignedBigInteger('reference_id')->nullable()->after('reference_type');

            $table->index(['reference_type', 'reference_id']);
            $table->index('transaction_type');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['reference_type', 'reference_id']);
            $table->dropIndex(['transaction_type']);
            $table->dropConstrainedForeignId('warehouse_id');
            $table->dropColumn(['transaction_type', 'reference_type', 'reference_id']);
        });
    }
};
