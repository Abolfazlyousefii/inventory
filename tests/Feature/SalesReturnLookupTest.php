<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesReturnLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_route_is_not_registered_in_phase_one(): void
    {
        $this->assertFalse(\Route::has('sales-returns.apply'));
    }

    public function test_customer_search_requires_sales_return_create_permission(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson(route('sales-returns.customers.search'))->assertForbidden();
    }
}
