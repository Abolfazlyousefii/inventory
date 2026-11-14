<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // NULL preserves the unknown physical status of every historical row.
        if (! Schema::hasColumn('sales_return_documents', 'return_flow')) {
            Schema::table('sales_return_documents', fn (Blueprint $t) => $t->string('return_flow', 30)->nullable());
        }
        if (! Schema::hasColumn('sales_return_documents', 'financial_only_reason')) {
            Schema::table('sales_return_documents', fn (Blueprint $t) => $t->text('financial_only_reason')->nullable());
        }
        if (! Schema::hasColumn('sales_return_documents', 'submission_token')) {
            Schema::table('sales_return_documents', fn (Blueprint $t) => $t->uuid('submission_token')->nullable());
        }
        if (! Schema::hasColumn('sales_return_documents', 'submission_fingerprint')) {
            Schema::table('sales_return_documents', fn (Blueprint $t) => $t->char('submission_fingerprint',64)->nullable());
        }
        if (! Schema::hasIndex('sales_return_documents', 'sr_submission_token_unique')) {
            Schema::table('sales_return_documents', fn (Blueprint $t) => $t->unique('submission_token', 'sr_submission_token_unique'));
        }
    }

    public function down(): void
    {
        // Classification and its reason are audit evidence; never erase them on rollback.
    }
};
