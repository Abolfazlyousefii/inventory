<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_settings', function (Blueprint $table) {
            $table->boolean('pilot_mode')->default(true)->after('cycle_day');
            $table->boolean('seller_visibility_enabled')->default(false)->after('pilot_mode');
            $table->boolean('targets_enabled')->default(false)->after('seller_visibility_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('commission_settings', function (Blueprint $table) {
            $table->dropColumn(['pilot_mode', 'seller_visibility_enabled', 'targets_enabled']);
        });
    }
};
