<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('warehouse_transfers', function (Blueprint $table) {
            if (! Schema::hasColumn('warehouse_transfers', 'receiver_user_id')) {
                $table->foreignId('receiver_user_id')
                    ->nullable()
                    ->after('beneficiary_name')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('warehouse_transfers', 'receiver_name_snapshot')) {
                $table->string('receiver_name_snapshot')
                    ->nullable()
                    ->after('receiver_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_transfers', function (Blueprint $table) {
            if (Schema::hasColumn('warehouse_transfers', 'receiver_user_id')) {
                $table->dropConstrainedForeignId('receiver_user_id');
            }

            if (Schema::hasColumn('warehouse_transfers', 'receiver_name_snapshot')) {
                $table->dropColumn('receiver_name_snapshot');
            }
        });
    }
};
