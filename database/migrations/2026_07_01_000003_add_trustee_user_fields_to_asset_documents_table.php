<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('asset_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('asset_documents', 'trustee_user_id')) {
                $table->foreignId('trustee_user_id')
                    ->nullable()
                    ->after('personnel_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('asset_documents', 'trustee_name_snapshot')) {
                $table->string('trustee_name_snapshot')
                    ->nullable()
                    ->after('trustee_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('asset_documents', function (Blueprint $table) {
            if (Schema::hasColumn('asset_documents', 'trustee_user_id')) {
                $table->dropConstrainedForeignId('trustee_user_id');
            }

            if (Schema::hasColumn('asset_documents', 'trustee_name_snapshot')) {
                $table->dropColumn('trustee_name_snapshot');
            }
        });
    }
};
