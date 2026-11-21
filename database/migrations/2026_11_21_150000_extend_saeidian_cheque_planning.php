<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::table('saeidian_cheque_entries',function(Blueprint $t){
  $t->uuid('submission_token')->nullable()->unique();$t->string('category',20)->nullable();$t->string('status',16)->default('active');
  $t->text('status_reason')->nullable();$t->foreignId('status_changed_by')->nullable()->constrained('users')->restrictOnDelete();$t->timestamp('status_changed_at')->nullable();
 });}
 public function down(): void {Schema::table('saeidian_cheque_entries',function(Blueprint $t){$t->dropConstrainedForeignId('status_changed_by');$t->dropColumn(['submission_token','category','status','status_reason','status_changed_at']);});}
};
