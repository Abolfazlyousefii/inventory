<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesReturnPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_permission_cannot_open_index(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('sales-returns.index'))->assertForbidden();
    }

    public function test_user_without_export_permission_cannot_export_excel(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('sales-returns.export.excel'))->assertForbidden();
    }
}
