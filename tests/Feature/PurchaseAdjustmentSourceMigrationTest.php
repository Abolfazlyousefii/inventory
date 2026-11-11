<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

class PurchaseAdjustmentSourceMigrationTest extends TestCase
{
    public function test_only_confirmed_legacy_adjustment_supplier_is_marked_and_no_purchase_data_is_changed(): void
    {
        Schema::create('suppliers', function(Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('purchases', function(Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('total_amount');
            $table->timestamps();
        });
        DB::table('suppliers')->insert([
            ['id'=>1,'name'=>'کسری و اضافه'],
            ['id'=>2,'name'=>'تأمین‌کننده واقعی'],
            ['id'=>3,'name'=>'کسری و اضافه - نام مشابه'],
        ]);
        DB::table('purchases')->insert([
            ['id'=>7,'supplier_id'=>1,'total_amount'=>12345],
            ['id'=>8,'supplier_id'=>2,'total_amount'=>99999],
        ]);

        $migration = require base_path('database/migrations/2026_11_11_120000_add_adjustment_source_flag_to_suppliers.php');
        $migration->up();

        $this->assertSame(1,(int)DB::table('suppliers')->where('id',1)->value('is_inventory_adjustment_source'));
        $this->assertSame(0,(int)DB::table('suppliers')->where('id',2)->value('is_inventory_adjustment_source'));
        $this->assertSame(0,(int)DB::table('suppliers')->where('id',3)->value('is_inventory_adjustment_source'));
        $this->assertDatabaseHas('purchases',['id'=>7,'supplier_id'=>1,'total_amount'=>12345]);
        $this->assertDatabaseHas('purchases',['id'=>8,'supplier_id'=>2,'total_amount'=>99999]);
        $this->assertDatabaseCount('purchases',2);
    }

    public function test_supplier_id_one_is_not_blocked_when_it_is_a_real_supplier(): void
    {
        Schema::create('suppliers', function(Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        DB::table('suppliers')->insert(['id'=>1,'name'=>'تأمین‌کننده واقعی']);

        $migration = require base_path('database/migrations/2026_11_11_120000_add_adjustment_source_flag_to_suppliers.php');
        $migration->up();

        $this->assertSame(0,(int)DB::table('suppliers')->where('id',1)->value('is_inventory_adjustment_source'));
    }
}
