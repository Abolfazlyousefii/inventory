<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

class SalesReturnFlowMigrationTest extends TestCase
{
    public function test_historical_rows_remain_unclassified_and_unchanged_on_replay_and_rollback(): void
    {
        Schema::create('sales_return_documents', function (Blueprint $t) {
            $t->id();$t->string('document_number');$t->string('status');$t->bigInteger('total_refund_amount');
        });
        DB::table('sales_return_documents')->insert([
            ['id'=>442,'document_number'=>'432','status'=>'applied','total_refund_amount'=>473860000],
            ['id'=>443,'document_number'=>'433','status'=>'draft','total_refund_amount'=>0],
        ]);
        $snapshot=fn()=>DB::table('sales_return_documents')->orderBy('id')->get(['id','document_number','status','total_refund_amount'])->toJson();
        $before=$snapshot();
        $migration=require database_path('migrations/2026_11_14_150000_add_explicit_sales_return_flow.php');
        $migration->up();
        $this->assertSame($before,$snapshot());
        $this->assertSame(2,DB::table('sales_return_documents')->whereNull('return_flow')->whereNull('submission_token')->count());
        DB::table('sales_return_documents')->where('id',443)->update(['return_flow'=>'financial_only','financial_only_reason'=>'تأیید عدم تحویل کالا']);
        $migration->up();$migration->down();
        $this->assertSame($before,$snapshot());
        $this->assertSame('financial_only',DB::table('sales_return_documents')->where('id',443)->value('return_flow'));
        $this->assertTrue(Schema::hasIndex('sales_return_documents','sr_submission_token_unique'));
    }
}
