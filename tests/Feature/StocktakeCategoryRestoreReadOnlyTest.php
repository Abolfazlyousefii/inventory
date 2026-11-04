<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase-one recovery: all category write endpoints must stay closed until the
 * category stocktake is reconciled with protected reserve/bundle snapshots.
 */
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

    public function test_category_save_is_blocked_in_preview_without_writing_stock(): void
    {
        $this->withoutMiddleware();

        $this->postJson('/stock-count-documents/category-document', [
            'category_id' => 1,
            'actual_quantities' => ['100' => 0],
        ])->assertStatus(503);
    }
}
