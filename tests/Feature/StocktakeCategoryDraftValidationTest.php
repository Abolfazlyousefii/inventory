<?php

namespace Tests\Feature;

use App\Http\Controllers\StocktakeController;
use App\Services\StockCountDocumentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

class StocktakeCategoryDraftValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('parent_id')->nullable();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('category_id')->nullable();
        });

        DB::table('categories')->insert(['id' => 1, 'name' => 'Category']);
        DB::table('products')->insert(['id' => 1, 'name' => 'Uncounted product', 'category_id' => 1]);

        $this->mock(StockCountDocumentService::class);
    }

    public function test_new_category_draft_without_any_explicit_count_is_rejected_before_writes(): void
    {
        $request = Request::create('/stock-count-documents/category-drafts', 'POST', [
            'category_id' => 1,
            'document_date' => '2026-11-05',
            'products' => [
                ['product_id' => 1, 'actual_quantities' => []],
            ],
        ]);

        $controller = app(StocktakeController::class);
        $method = new ReflectionMethod($controller, 'performStoreCategoryDrafts');
        $method->setAccessible(true);

        $this->expectException(ValidationException::class);
        $method->invoke($controller, $request);
    }
}
