<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', fn (Blueprint $t) => $t->enum('type', ['central', 'return', 'scrap', 'personnel', 'showroom'])->change());
        Schema::create('warehouse_transfer_submissions', function (Blueprint $t) {
            $t->uuid('token')->primary();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('warehouse_transfer_id')->nullable()->constrained()->nullOnDelete();
            $t->string('fingerprint', 64);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('warehouse_transfer_submissions')->exists()) {
            throw new RuntimeException('Retain showroom submission history; use a forward migration.');
        }
        Schema::dropIfExists('warehouse_transfer_submissions');
        // Do not remove an enum value already used by a real warehouse.
    }
};
