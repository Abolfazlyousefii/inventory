<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('preinvoice_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('preinvoice_orders', 'auto_saved_at')) {
                $table->timestamp('auto_saved_at')->nullable()->after('stock_released_at');
            }
            if (! Schema::hasColumn('preinvoice_orders', 'is_auto_draft')) {
                $table->boolean('is_auto_draft')->default(false)->after('auto_saved_at');
            }
            if (! Schema::hasColumn('preinvoice_orders', 'draft_token')) {
                $table->string('draft_token')->nullable()->after('is_auto_draft');
                $table->index('draft_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('preinvoice_orders', function (Blueprint $table) {
            if (Schema::hasColumn('preinvoice_orders', 'draft_token')) {
                $table->dropIndex(['draft_token']);
                $table->dropColumn('draft_token');
            }
            if (Schema::hasColumn('preinvoice_orders', 'is_auto_draft')) {
                $table->dropColumn('is_auto_draft');
            }
            if (Schema::hasColumn('preinvoice_orders', 'auto_saved_at')) {
                $table->dropColumn('auto_saved_at');
            }
        });
    }
};
