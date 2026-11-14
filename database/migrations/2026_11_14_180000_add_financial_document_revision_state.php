<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['version', 'cancelled_at'] as $column) {
            if (! Schema::hasColumn('financial_account_documents', $column)) {
                Schema::table('financial_account_documents', function (Blueprint $table) use ($column) {
                    if ($column === 'version') {
                        $table->unsignedInteger('version')->default(1);
                    } else {
                        $table->timestamp('cancelled_at')->nullable();
                    }
                });
            }
        }
    }

    public function down(): void
    {
        // Retain cancellation and version history.
    }
};
