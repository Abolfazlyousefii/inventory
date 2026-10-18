<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Support\PermissionCatalog;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            if (!Schema::hasColumn('permissions', 'key')) {
                $table->string('key')->nullable()->unique()->after('name');
            }

            if (!Schema::hasColumn('permissions', 'group')) {
                $table->string('group')->nullable()->after('key');
            }
        });

        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'permission_id']);
        });

        $permissions = PermissionCatalog::all();

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $permission['key']],
                $permission + ['guard_name' => 'web', 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permissions');

        Schema::table('permissions', function (Blueprint $table) {
            if (Schema::hasColumn('permissions', 'key')) {
                $table->dropUnique(['key']);
                $table->dropColumn('key');
            }
            if (Schema::hasColumn('permissions', 'group')) {
                $table->dropColumn('group');
            }
        });
    }
};
