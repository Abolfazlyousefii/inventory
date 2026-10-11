<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_return_sequences', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->unsignedBigInteger('last_number')->default(0);
        });
        DB::table('purchase_return_sequences')->insert(['id'=>1,'last_number'=>0]);
        Schema::create('purchase_returns', function (Blueprint $t) {
            $t->id();$t->string('document_number',20)->unique();$t->uuid('submission_token')->unique();
            // Snapshot references remain available after a cancelled return's purchase is removed.
            $t->unsignedBigInteger('supplier_id')->index();$t->unsignedBigInteger('purchase_id')->index();
            $t->unsignedBigInteger('warehouse_id');$t->string('supplier_name');$t->date('returned_at')->index();
            $t->unsignedBigInteger('total_amount')->default(0);$t->unsignedInteger('total_quantity')->default(0);
            $t->text('note')->nullable();$t->unsignedBigInteger('created_by');$t->unsignedBigInteger('updated_by');
            $t->unsignedInteger('version')->default(1);$t->unsignedBigInteger('deleted_by')->nullable();
            $t->text('delete_reason')->nullable();$t->softDeletes();$t->timestamps();
        });
        Schema::create('purchase_return_items', function (Blueprint $t) {
            $t->id();$t->foreignId('purchase_return_id')->constrained('purchase_returns')->cascadeOnDelete();
            $t->unsignedBigInteger('purchase_item_id')->index();$t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('product_variant_id')->nullable();$t->string('product_name');
            $t->string('variant_name')->nullable();$t->string('product_code')->nullable();
            $t->unsignedInteger('quantity');$t->unsignedBigInteger('buy_price');$t->unsignedBigInteger('line_total');$t->timestamps();
            $t->unique(['purchase_return_id','purchase_item_id']);
        });
        Schema::create('purchase_return_revisions', function (Blueprint $t) {
            $t->id();$t->foreignId('purchase_return_id')->constrained('purchase_returns')->cascadeOnDelete();
            $t->string('action');$t->unsignedBigInteger('user_id');$t->json('before')->nullable();
            $t->json('after')->nullable();$t->text('note')->nullable();$t->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('purchase_return_revisions');Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');Schema::dropIfExists('purchase_return_sequences');
    }
};
