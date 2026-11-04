<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Route-level regression for the category stocktake restoration. */
final class StocktakeCategoryRestoreReadOnlyTest extends TestCase
{
    public function test_restored_category_routes_exist(): void
    {
        foreach ([
            'stock-count-documents.category-workspace',
            'stock-count-documents.category-document.store',
            'stock-count-documents.category-document.finalize',
        ] as $name) {
            $this->assertNotNull(Route::getRoutes()->getByName($name), $name);
        }
    }

    public function test_category_store_routes_to_unified_controller(): void
    {
        $route = Route::getRoutes()->getByName('stock-count-documents.category-document.store');
        $this->assertSame(
            \App\Http\Controllers\CategoryStocktakeController::class.'@store',
            $route->getActionName()
        );
    }
}
