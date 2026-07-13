<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductCreateRegressionTest extends TestCase
{
    public function test_product_create_view_still_contains_core_builder_hooks(): void
    {
        $view = file_get_contents(resource_path('views/products/create.blade.php'));

        foreach (['productCreateForm', 'model_list_ids', 'use_models', 'use_designs'] as $token) {
            $this->assertStringContainsString($token, $view);
        }
    }
}
