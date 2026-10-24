<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing installations may already have this operational column.
        // Do not rewrite their tokens or rebuild indexes during this repair.
        if (Schema::hasTable('preinvoice_orders') && ! Schema::hasColumn('preinvoice_orders', 'submission_token')) {
            Schema::table('preinvoice_orders', function (Blueprint $table) {
                $table->uuid('submission_token')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        // Preserve submission identities on rollback: deleting these values
        // would let an already submitted form be submitted a second time.
    }
};
