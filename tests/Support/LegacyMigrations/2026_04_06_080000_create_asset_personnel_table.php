<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('asset_personnel', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('personnel_code')->unique();
            $table->string('national_code', 20)->nullable();
            $table->string('department')->nullable();
            $table->string('position')->nullable();
            $table->string('mobile', 30)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['full_name', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_personnel');
    }
};
