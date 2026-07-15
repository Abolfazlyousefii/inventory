<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('asset_personnel', function (Blueprint $table) {
            if (! Schema::hasColumn('asset_personnel', 'user_id')) {
                $table->foreignId('user_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('asset_personnel', 'user_name_snapshot')) {
                $table->string('user_name_snapshot')
                    ->nullable()
                    ->after('user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('asset_personnel', function (Blueprint $table) {
            if (Schema::hasColumn('asset_personnel', 'user_id')) {
                $table->dropConstrainedForeignId('user_id');
            }

            if (Schema::hasColumn('asset_personnel', 'user_name_snapshot')) {
                $table->dropColumn('user_name_snapshot');
            }
        });
    }
};
