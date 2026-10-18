<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            $table->unsignedBigInteger('amount')->default(0)->after('cheque_number');
            $table->date('received_at')->nullable()->after('due_date');
            $table->string('customer_name')->nullable()->after('received_at');
            $table->string('customer_code')->nullable()->after('customer_name');
            $table->string('branch_name')->nullable()->after('bank_name');
            $table->string('account_number')->nullable()->after('branch_name');
            $table->string('account_holder')->nullable()->after('account_number');
        });
    }

    public function down(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            $table->dropColumn([
                'amount',
                'received_at',
                'customer_name',
                'customer_code',
                'branch_name',
                'account_number',
                'account_holder',
            ]);
        });
    }
};
