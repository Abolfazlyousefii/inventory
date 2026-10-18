<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->enum('reason', ['purchase','sale','return','transfer','adjustment'])
                ->default('adjustment')
                ->after('type');

            $table->unsignedInteger('stock_before')->default(0)->after('quantity');
            $table->unsignedInteger('stock_after')->default(0)->after('stock_before');

            $table->string('reference')->nullable()->after('note'); // شماره فاکتور/حواله/هر ارجاع
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn(['reason','stock_before','stock_after','reference']);
        });
    }
};
