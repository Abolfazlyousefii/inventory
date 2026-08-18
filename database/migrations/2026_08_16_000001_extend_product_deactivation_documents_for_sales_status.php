<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_deactivation_documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('product_deactivation_documents', 'action_type')) {
                $table->string('action_type', 20)->nullable()->after('document_number')->index();
            }
            if (! Schema::hasColumn('product_deactivation_documents', 'scope_type')) {
                $table->string('scope_type', 30)->nullable()->after('action_type')->index();
            }
        });

        Schema::table('product_deactivation_document_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('product_deactivation_document_items', 'action_type')) {
                $table->string('action_type', 20)->nullable()->after('document_id');
            }
            if (! Schema::hasColumn('product_deactivation_document_items', 'scope_type')) {
                $table->string('scope_type', 30)->nullable()->after('action_type');
            }
            if (! Schema::hasColumn('product_deactivation_document_items', 'previous_sales_enabled')) {
                $table->boolean('previous_sales_enabled')->nullable()->after('deactivation_status');
            }
            if (! Schema::hasColumn('product_deactivation_document_items', 'new_sales_enabled')) {
                $table->boolean('new_sales_enabled')->nullable()->after('previous_sales_enabled');
            }
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive: these fields are part of the audit history.
    }
};
