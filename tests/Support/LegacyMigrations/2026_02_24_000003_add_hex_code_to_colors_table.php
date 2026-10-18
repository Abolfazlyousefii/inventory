<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colors', function (Blueprint $table) {
            if (!Schema::hasColumn('colors', 'hex_code')) {
                $table->string('hex_code', 7)->default('#9CA3AF')->after('name');
            }
        });

        DB::table('colors')->whereNull('hex_code')->update(['hex_code' => '#9CA3AF']);
    }

    public function down(): void
    {
        Schema::table('colors', function (Blueprint $table) {
            if (Schema::hasColumn('colors', 'hex_code')) {
                $table->dropColumn('hex_code');
            }
        });
    }
};
