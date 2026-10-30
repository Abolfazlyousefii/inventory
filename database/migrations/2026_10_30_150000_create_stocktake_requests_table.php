<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stocktake_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('operation', 16);
            $table->uuid('request_id');
            $table->char('payload_hash', 64);
            $table->json('response')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'operation', 'request_id'], 'stocktake_request_identity');
        });
    }
    public function down(): void { Schema::dropIfExists('stocktake_requests'); }
};
