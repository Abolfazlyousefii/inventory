<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cny_price_revisions', function (Blueprint $table) {
            $table->index(['product_id', 'created_at'], 'cny_revisions_product_history_idx');
        });
        Schema::table('cny_exchange_rates', function (Blueprint $table) {
            $table->index(['quoted_at', 'id'], 'cny_rates_chart_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cny_price_revisions', fn (Blueprint $table) => $table->dropIndex('cny_revisions_product_history_idx'));
        Schema::table('cny_exchange_rates', fn (Blueprint $table) => $table->dropIndex('cny_rates_chart_idx'));
    }
};
