<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('personnel_steps',function(Blueprint $table){$table->timestamp('superseded_at')->nullable();$table->index(['personnel_entry_id','superseded_at']);}); }
    public function down(): void { Schema::table('personnel_steps',function(Blueprint $table){$table->dropIndex(['personnel_entry_id','superseded_at']);$table->dropColumn('superseded_at');}); }
};
