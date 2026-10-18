<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('asset_documents', function (Blueprint $table) {
            if (!Schema::hasColumn('asset_documents', 'signed_form_path')) {
                $table->string('signed_form_path')->nullable()->after('description');
            }
            if (!Schema::hasColumn('asset_documents', 'signed_form_original_name')) {
                $table->string('signed_form_original_name')->nullable()->after('signed_form_path');
            }
            if (!Schema::hasColumn('asset_documents', 'signed_form_mime')) {
                $table->string('signed_form_mime')->nullable()->after('signed_form_original_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('asset_documents', function (Blueprint $table) {
            $table->dropColumn(['signed_form_mime', 'signed_form_original_name', 'signed_form_path']);
        });
    }
};
