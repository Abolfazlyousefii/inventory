<?php

namespace Tests\Feature;

use App\Http\Controllers\PurchaseController;
use App\Http\Requests\PurchaseIndexRequest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class PurchaseFilteredSummaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_id');
            $table->integer('total_amount');
            $table->timestamp('purchased_at')->nullable();
            $table->timestamps();
        });
        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_id');
        });
        DB::table('suppliers')->insert([['id' => 1, 'name' => 'Selected'], ['id' => 2, 'name' => 'Other']]);
        // More matching purchases than one page; purchased_at must not affect registration-date filters.
        for ($i = 0; $i < 22; $i++) {
            DB::table('purchases')->insert(['supplier_id' => 1, 'total_amount' => 100, 'purchased_at' => '2026-09-01', 'created_at' => '2026-10-15 23:59:59']);
        }
        DB::table('purchases')->insert([
            ['supplier_id' => 2, 'total_amount' => 700, 'created_at' => '2026-10-15 00:00:00'],
            ['supplier_id' => 1, 'total_amount' => 500, 'created_at' => '2026-10-16 00:00:00'],
        ]);
    }

    public function test_summary_uses_all_filtered_documents_before_pagination(): void
    {
        foreach ([
            [[], 3400, 24],
            [['supplier_id' => 1], 2700, 23],
            [['date_from' => '2026-10-15', 'date_to' => '2026-10-15'], 2900, 23],
            [['supplier_id' => 1, 'date_from' => '2026-10-15', 'date_to' => '2026-10-15'], 2200, 22],
            [['date_from' => '2026-10-17', 'date_to' => '2026-10-17'], 0, 0],
        ] as [$filters, $amount, $count]) {
            $request = Mockery::mock(PurchaseIndexRequest::class);
            $request->shouldReceive('validated')->once()->andReturn($filters);
            $data = app(PurchaseController::class)->index($request)->getData();
            $this->assertSame($amount, $data['totalAllAmount'], json_encode($filters));
            $this->assertSame($count, $data['totalAllCount']);
            $this->assertSame($count, $data['purchases']->total());
            $this->assertCount(min(20, $count), $data['purchases']);
        }
    }
}
