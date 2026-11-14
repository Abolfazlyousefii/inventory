<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

class SupplierTransactionMigrationSafetyTest extends TestCase
{
    public function test_replay_and_rollback_preserve_audited_transactions(): void
    {
        Schema::create('users',fn(Blueprint $t)=>$t->id());Schema::create('suppliers',fn(Blueprint $t)=>$t->id());
        DB::table('users')->insert(['id'=>1]);DB::table('suppliers')->insert(['id'=>1]);
        $migration=require database_path('migrations/2026_11_11_180000_create_supplier_account_transactions_table.php');
        $migration->up();
        DB::table('supplier_account_transactions')->insert(['id'=>1,'submission_token'=>'11111111-1111-4111-8111-111111111111',
            'supplier_id'=>1,'direction'=>'payment','amount'=>1234,'transaction_date'=>'2026-11-14 12:00:00','created_by'=>1]);
        $before=DB::table('supplier_account_transactions')->get()->toJson();
        $migration->up();$migration->down();
        $this->assertTrue(Schema::hasTable('supplier_account_transactions'));
        $this->assertSame($before,DB::table('supplier_account_transactions')->get()->toJson());
    }

    public function test_partial_table_requires_review_instead_of_silent_schema_repair(): void
    {
        Schema::create('supplier_account_transactions',fn(Blueprint $t)=>$t->id());
        DB::table('supplier_account_transactions')->insert(['id'=>7]);
        $migration=require database_path('migrations/2026_11_11_180000_create_supplier_account_transactions_table.php');
        try {$migration->up();$this->fail('Partial table was accepted');}
        catch(\RuntimeException $e){$this->assertStringContainsString('review',$e->getMessage());}
        $this->assertSame(['id'],Schema::getColumnListing('supplier_account_transactions'));
        $this->assertSame(7,(int)DB::table('supplier_account_transactions')->value('id'));
    }
}
