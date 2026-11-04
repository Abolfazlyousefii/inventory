<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Category stocktake write routes are enabled only after counted-only snapshots
 * and protected reserve/bundle validation are implemented.
 */
final class StocktakeCategoryRouteActivationTest extends TestCase
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

    public function test_category_save_endpoint_reaches_validation_instead_of_preview_block(): void
    {
        $this->withoutMiddleware();

        $this->postJson('/stock-count-documents/category-document', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);
    }
}
