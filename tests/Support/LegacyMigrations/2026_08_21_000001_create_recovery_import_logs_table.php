<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('recovery_import_logs', function(Blueprint $table){
            $table->id();
            $table->string('old_number');
            $table->string('new_number')->nullable();
            $table->string('status')->default('imported');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recovery_import_logs');
    }
};
