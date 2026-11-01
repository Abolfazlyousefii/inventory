<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_count_freezes', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->boolean('enabled')->default(false);
            $t->unsignedBigInteger('version')->default(0);
            $t->boolean('all_products')->default(false);
            $t->json('category_ids')->nullable();
            $t->json('product_ids')->nullable();
            $t->unsignedBigInteger('changed_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_count_freezes');
    }
};
