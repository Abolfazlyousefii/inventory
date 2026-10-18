<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_lists', function (Blueprint $table) {
            $table->id();
            $table->string('brand', 100);
            $table->string('model_name', 255);
            $table->timestamps();

            $table->unique(['brand', 'model_name']);
            $table->index('brand');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_lists');
    }
};
