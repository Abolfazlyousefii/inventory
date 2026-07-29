<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('manager_crm_user_id')->nullable()->after('manager_id')->index();
            $table->boolean('is_crm_managed')->default(false)->after('sync_source')->index();
            $table->boolean('can_access_erp')->default(true)->after('is_active')->index();
            $table->boolean('is_seller')->default(false)->after('can_access_erp')->index();
        });

        DB::table('users')
            ->whereNotNull('crm_user_id')
            ->update(['is_crm_managed' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['manager_crm_user_id']);
            $table->dropIndex(['is_crm_managed']);
            $table->dropIndex(['can_access_erp']);
            $table->dropIndex(['is_seller']);
            $table->dropColumn(['manager_crm_user_id', 'is_crm_managed', 'can_access_erp', 'is_seller']);
        });
    }
};
