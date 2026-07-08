<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('preinvoice_orders', 'payment_terms_note')) {
            Schema::table('preinvoice_orders', function (Blueprint $table) {
                $table->text('payment_terms_note')->nullable()->after('description');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('preinvoice_orders', 'payment_terms_note')) {
            Schema::table('preinvoice_orders', function (Blueprint $table) {
                $table->dropColumn('payment_terms_note');
            });
        }
    }
};
