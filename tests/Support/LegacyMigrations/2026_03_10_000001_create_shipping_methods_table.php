<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('price')->default(0);
            $table->timestamps();
        });

        DB::table('shipping_methods')->insert([
            ['name' => 'ارسال فوری', 'price' => 50000, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'پیک', 'price' => 30000, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'مراجعه حضوری', 'price' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_methods');
    }
};
