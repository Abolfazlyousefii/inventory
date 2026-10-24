<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('city')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->softDeletes();
        });

        Schema::create('customer_phones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 20)->unique();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->index(['customer_id', 'is_primary']);
        });

        Schema::create('customer_login_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 20);
            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('request_ip', 45)->nullable();
            $table->timestamps();
            $table->index(['phone', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_login_codes');
        Schema::dropIfExists('customer_phones');
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn([
            'name', 'company_name', 'city', 'notes', 'is_active', 'deleted_at',
        ]));
    }
};
