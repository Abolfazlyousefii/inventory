<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_commission_departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('org_commission_department_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('org_commission_departments')->cascadeOnDelete();
            $table->string('name');
            $table->string('role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('org_commission_documents', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->string('document_number')->unique();
            $table->date('period_from');
            $table->date('period_to');
            $table->bigInteger('total_seller_commission')->default(0);
            $table->bigInteger('total_allocated')->default(0);
            $table->string('notes', 2000)->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->foreignId('confirmed_by')->nullable()->constrained('users');
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users');
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
        });

        Schema::create('org_commission_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('org_commission_documents')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('org_commission_departments');
            $table->decimal('percentage', 8, 4);
            $table->bigInteger('allocated_amount')->default(0);
            $table->timestamps();

            $table->unique(['document_id', 'department_id']);
        });

        Schema::create('org_commission_member_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('allocation_id')->constrained('org_commission_allocations')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('org_commission_department_members');
            $table->bigInteger('share_amount')->default(0);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['allocation_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_commission_member_shares');
        Schema::dropIfExists('org_commission_allocations');
        Schema::dropIfExists('org_commission_documents');
        Schema::dropIfExists('org_commission_department_members');
        Schema::dropIfExists('org_commission_departments');
    }
};
