<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('purchase_drafts',function(Blueprint $table){
            $table->uuid('token')->primary();
            $table->foreignId('user_id')->constrained('users');
            $table->string('status',16)->default('active');
            $table->unsignedInteger('revision')->default(0);
            $table->longText('payload')->nullable();
            $table->foreignId('purchase_id')->nullable()->constrained('purchases')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id','status','updated_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('purchase_drafts'); }
};
