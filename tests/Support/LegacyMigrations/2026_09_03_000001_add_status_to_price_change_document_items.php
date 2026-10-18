<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_change_document_items', function (Blueprint $table) {
            // Invalid previews can legitimately contain a negative calculated
            // price, and they must still be retained for review.
            $table->bigInteger('new_price')->change();
            $table->string('status')->default('valid')->after('rounding_mode');
            $table->text('error_message')->nullable()->after('status');
            $table->json('validation_details')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('price_change_document_items', function (Blueprint $table) {
            $table->dropColumn(['status', 'error_message', 'validation_details']);
            $table->unsignedBigInteger('new_price')->change();
        });
    }
};
