<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_reads', function (Blueprint $table) {
            $table->foreignId('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->primary(['notification_id', 'user_id']);
            $table->index(['user_id', 'notification_id']);
        });
        Schema::create('notification_role_targets', function (Blueprint $table) {
            $table->foreignId('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->string('role');
            $table->primary(['notification_id', 'role']);
            $table->index(['role', 'notification_id']);
        });
        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['user_id', 'read_at', 'created_at', 'id'], 'notifications_user_inbox_idx');
            $table->index(['role', 'read_at', 'created_at', 'id'], 'notifications_role_inbox_idx');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_user_inbox_idx');
            $table->dropIndex('notifications_role_inbox_idx');
        });
        Schema::dropIfExists('notification_role_targets');
        Schema::dropIfExists('notification_reads');
    }
};
