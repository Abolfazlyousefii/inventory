<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cny_exchange_rates',function(Blueprint $table){
            $table->id();$table->string('provider',30);$table->unsignedBigInteger('rate_rial');$table->timestamp('quoted_at');$table->timestamps();
        });
        Schema::create('product_cny_pricings',function(Blueprint $table){
            $table->id();$table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('buy_cny_units');$table->boolean('enabled')->default(true);
            $table->foreignId('applied_rate_id')->constrained('cny_exchange_rates');
            $table->foreignId('approved_by')->constrained('users');$table->string('policy',80);$table->timestamps();
        });
        Schema::create('cny_price_revisions',function(Blueprint $table){
            $table->id();$table->unsignedBigInteger('product_id');$table->unsignedBigInteger('buy_cny_units');
            $table->foreignId('rate_id')->constrained('cny_exchange_rates');$table->unsignedBigInteger('old_price');$table->unsignedBigInteger('new_price');
            $table->string('policy',80);$table->string('reason',30);$table->unsignedBigInteger('approved_by')->nullable();$table->timestamps();
        });
        Schema::table('purchases',function(Blueprint $table){
            $table->string('purchase_origin',16)->default('domestic');$table->foreignId('cny_rate_id')->nullable()->constrained('cny_exchange_rates');
        });
        Schema::table('purchase_items',function(Blueprint $table){$table->unsignedBigInteger('buy_cny_units')->nullable();$table->string('cny_policy',80)->nullable();});
    }
    public function down(): void
    {
        Schema::table('purchase_items',fn(Blueprint $table)=>$table->dropColumn(['buy_cny_units','cny_policy']));
        Schema::table('purchases',function(Blueprint $table){$table->dropConstrainedForeignId('cny_rate_id');$table->dropColumn('purchase_origin');});
        Schema::dropIfExists('cny_price_revisions');Schema::dropIfExists('product_cny_pricings');Schema::dropIfExists('cny_exchange_rates');
    }
};
