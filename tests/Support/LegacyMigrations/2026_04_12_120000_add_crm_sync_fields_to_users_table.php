<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('crm_user_id')->nullable()->unique()->after('external_crm_id');
            $table->string('username')->nullable()->after('phone');
            $table->boolean('is_active')->default(true)->after('username');
            $table->string('sync_source')->nullable()->after('is_active');
            $table->json('crm_role_raw')->nullable()->after('sync_source');
            $table->timestamp('synced_at')->nullable()->after('crm_role_raw');
            $table->json('last_crm_payload')->nullable()->after('synced_at');
            $table->timestamp('crm_created_at')->nullable()->after('last_crm_payload');
            $table->timestamp('crm_updated_at')->nullable()->after('crm_created_at');
            $table->string('avatar')->nullable()->after('crm_updated_at');
            $table->string('department')->nullable()->after('avatar');
            $table->string('position')->nullable()->after('department');
            $table->string('personnel_code')->nullable()->after('position');
            $table->string('branch')->nullable()->after('personnel_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'crm_user_id',
                'username',
                'is_active',
                'sync_source',
                'crm_role_raw',
                'synced_at',
                'last_crm_payload',
                'crm_created_at',
                'crm_updated_at',
                'avatar',
                'department',
                'position',
                'personnel_code',
                'branch',
            ]);
        });
    }
};

