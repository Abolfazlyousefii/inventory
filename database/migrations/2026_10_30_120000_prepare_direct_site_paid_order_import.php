<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('site_paid_order_import_cursor')) {
            Schema::create('site_paid_order_import_cursor', function (Blueprint $table) {
                $table->unsignedTinyInteger('id')->primary();
                $table->unsignedBigInteger('last_order_id')->default(0);
                $table->timestamps();
            });
        }
        DB::table('site_paid_order_import_cursor')->insertOrIgnore([
            'id' => 1, 'last_order_id' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Accounting identity only: no ERP login, active account, or assigned roles.
        // Never change an existing user's access or credentials during a migration.
        if (! DB::table('users')->where('username', 'ariya_site')->exists()) {
            DB::table('users')->insert([
                'name' => 'فروش سایت آریا', 'username' => 'ariya_site',
                'email' => 'ariya-site@inventory.invalid', 'password' => Hash::make(Str::random(64)),
                'is_seller' => true, 'is_active' => false, 'can_access_erp' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_paid_order_import_cursor');
        // Retain the disabled accounting identity: invoices may reference it.
    }
};
