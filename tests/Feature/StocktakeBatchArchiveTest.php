<?php

namespace Tests\Feature;

use App\Http\Controllers\StocktakeBatchArchiveController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class StocktakeBatchArchiveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('stock_count_documents', function (Blueprint $table) {
            $table->id();
            $table->string('document_number');
            $table->string('status');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->integer('total_increase')->default(0);
            $table->integer('total_decrease')->default(0);
        });
        Schema::create('stocktake_requests', function (Blueprint $table) {
            $table->id();
            $table->string('operation');
            $table->text('response')->nullable();
            $table->timestamps();
        });

        DB::table('products')->insert(['id' => 9, 'name' => 'کالای آزمایشی']);
        DB::table('stock_count_documents')->insert([
            ['id' => 213, 'document_number' => 'STC-213', 'status' => 'finalized', 'product_id' => 9, 'total_increase' => 3, 'total_decrease' => 0],
            ['id' => 214, 'document_number' => 'STC-214', 'status' => 'finalized', 'product_id' => 9, 'total_increase' => 0, 'total_decrease' => 2],
            ['id' => 215, 'document_number' => 'STC-215', 'status' => 'finalized', 'product_id' => 9, 'total_increase' => 1, 'total_decrease' => 0],
        ]);
    }

    public function test_it_groups_existing_documents_by_original_finalization_request_without_writes(): void
    {
        DB::table('stocktake_requests')->insert([
            ['id' => 2, 'operation' => 'finalize', 'response' => json_encode(['documents' => [['document_id' => 213]]]), 'created_at' => '2026-10-30 17:42:14'],
            ['id' => 16, 'operation' => 'finalize', 'response' => json_encode(['documents' => [['document_id' => 214], ['document_id' => 215]]]), 'created_at' => '2026-11-05 09:46:51'],
            ['id' => 17, 'operation' => 'drafts', 'response' => json_encode(['documents' => [['document_id' => 213]]]), 'created_at' => '2026-11-05 09:50:00'],
        ]);
        $before = DB::table('stock_count_documents')->orderBy('id')->get()->toArray();

        $view = app(StocktakeBatchArchiveController::class)->index();
        $batches = $view->getData()['batches'];
        $this->assertSame(2, $batches->total());
        $latest = $batches->getCollection()->first();
        $this->assertSame(16, $latest->id);
        $this->assertSame(2, $latest->document_count);
        $this->assertSame([214, 215], $latest->documents->pluck('id')->all());
        $this->assertSame(1, $latest->total_increase);
        $this->assertSame(2, $latest->total_decrease);
        $this->assertSame(0, $latest->missing_count);
        $this->assertEquals($before, DB::table('stock_count_documents')->orderBy('id')->get()->toArray());
    }

    public function test_it_ignores_invalid_response_items_and_reports_missing_documents(): void
    {
        DB::table('stocktake_requests')->insert([
            'id' => 16,
            'operation' => 'finalize',
            'response' => json_encode(['documents' => [
                ['document_id' => 213], ['document_id' => 213], ['document_id' => 9999],
                ['document_id' => 0], ['document_id' => 'not-a-number'],
            ]]),
            'created_at' => '2026-11-05 09:46:51',
        ]);
        $batch = app(StocktakeBatchArchiveController::class)->index()->getData()['batches']->getCollection()->first();
        $this->assertSame(2, $batch->document_count);
        $this->assertSame(1, $batch->missing_count);
        $this->assertSame([213], $batch->documents->pluck('id')->all());
        $this->assertSame([213, 9999], StocktakeBatchArchiveController::documentIds((string) DB::table('stocktake_requests')->value('response')));
        $this->assertSame([], StocktakeBatchArchiveController::documentIds('{broken json'));
    }
}
