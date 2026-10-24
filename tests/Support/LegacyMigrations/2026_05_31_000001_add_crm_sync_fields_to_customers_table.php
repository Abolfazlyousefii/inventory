<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'crm_customer_id')) {
                $table->string('crm_customer_id')->nullable()->unique()->after('id');
            }

            if (! Schema::hasColumn('customers', 'sync_source')) {
                $table->string('sync_source')->nullable()->index()->after('crm_customer_id');
            }

            if (! Schema::hasColumn('customers', 'synced_at')) {
                $table->timestamp('synced_at')->nullable()->after('sync_source');
            }

            if (! Schema::hasColumn('customers', 'crm_updated_at')) {
                $table->timestamp('crm_updated_at')->nullable()->after('synced_at');
            }

            if (! Schema::hasColumn('customers', 'last_crm_payload')) {
                $table->json('last_crm_payload')->nullable()->after('crm_updated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('customers', 'last_crm_payload') ? 'last_crm_payload' : null,
                Schema::hasColumn('customers', 'crm_updated_at') ? 'crm_updated_at' : null,
                Schema::hasColumn('customers', 'synced_at') ? 'synced_at' : null,
                Schema::hasColumn('customers', 'sync_source') ? 'sync_source' : null,
                Schema::hasColumn('customers', 'crm_customer_id') ? 'crm_customer_id' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
