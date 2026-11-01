<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('stock_count_document_items', 'bundle_free_at_start')) {
            Schema::table('stock_count_document_items', function (Blueprint $table): void {
                $table->unsignedInteger('bundle_free_at_start')->default(0);
            });
        }
        if (! Schema::hasColumn('stock_count_document_items', 'bundle_reserved_at_start')) {
            Schema::table('stock_count_document_items', function (Blueprint $table): void {
                $table->unsignedInteger('bundle_reserved_at_start')->default(0);
            });
        }
        if (! Schema::hasColumn('stock_count_document_items', 'site_held_at_start')) {
            Schema::table('stock_count_document_items', function (Blueprint $table): void {
                $table->unsignedInteger('site_held_at_start')->default(0);
            });
        }
    }

    public function down(): void
    {
        // Retain historical count snapshots: these columns may already exist
        // on older installations, and dropping them would destroy audit data.
    }
};
