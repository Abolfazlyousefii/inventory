<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('org_commission_department_members', fn (Blueprint $t) => $t->decimal('percentage', 8, 4)->nullable());
        Schema::table('org_commission_documents', fn (Blueprint $t) => $t->json('commission_settings_snapshot')->nullable());
    }

    public function down(): void
    {
        Schema::table('org_commission_documents', fn (Blueprint $t) => $t->dropColumn('commission_settings_snapshot'));
        Schema::table('org_commission_department_members', fn (Blueprint $t) => $t->dropColumn('percentage'));
    }
};
