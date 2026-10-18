<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('provinces')) {
            Schema::create('provinces', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique('province_name_uq');
                $table->string('slug')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        } else {
            Schema::table('provinces', function (Blueprint $table) {
                if (!Schema::hasColumn('provinces', 'slug')) {
                    $table->string('slug')->nullable()->after('name');
                }

                if (!Schema::hasColumn('provinces', 'is_active')) {
                    $table->boolean('is_active')->default(true)->after('slug');
                }

                if (!Schema::hasColumn('provinces', 'created_at')) {
                    $table->timestamps();
                }
            });
        }

        if (!Schema::hasTable('cities')) {
            Schema::create('cities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('province_id')->constrained('provinces')->cascadeOnDelete();
                $table->string('name');
                $table->string('slug')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['province_id', 'name'], 'city_prov_name_uq');
            });
        } else {
            Schema::table('cities', function (Blueprint $table) {
                if (!Schema::hasColumn('cities', 'slug')) {
                    $table->string('slug')->nullable()->after('name');
                }

                if (!Schema::hasColumn('cities', 'is_active')) {
                    $table->boolean('is_active')->default(true)->after('slug');
                }

                if (!Schema::hasColumn('cities', 'created_at')) {
                    $table->timestamps();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
        Schema::dropIfExists('provinces');
    }
};
