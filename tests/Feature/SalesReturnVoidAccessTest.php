<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SalesReturnVoidAccess;
use Mockery;
use Tests\TestCase;

class SalesReturnVoidAccessTest extends TestCase
{
    public function test_void_permission_grants_return_only_approval_without_edit_permission(): void
    {
        $user = $this->actor(['sales_returns.view', 'sales_returns.void_applied']);

        $this->assertTrue(SalesReturnVoidAccess::allowed($user, false));
    }

    public function test_edit_permission_does_not_grant_void_permission(): void
    {
        $user = $this->actor(['sales_returns.view', 'sales_returns.edit_applied']);

        $this->assertFalse(SalesReturnVoidAccess::allowed($user, false));
    }

    public function test_void_permission_without_return_list_access_is_not_enough_for_return_only_documents(): void
    {
        $user = $this->actor(['sales_returns.void_applied']);

        $this->assertFalse(SalesReturnVoidAccess::allowed($user, false));
    }

    public function test_protected_return_requires_warehouse_access_even_with_void_permission(): void
    {
        $user = $this->actor(['sales_returns.view', 'sales_returns.void_applied']);

        $this->assertFalse(SalesReturnVoidAccess::allowed($user, true));
    }

    public function test_protected_return_can_be_voided_with_return_and_warehouse_authorization(): void
    {
        $user = $this->actor([
            'sales_returns.view',
            'sales_returns.void_applied',
            'page.warehouse.inbound_queue',
        ]);

        $this->assertTrue(SalesReturnVoidAccess::allowed($user, true));
    }

    public function test_warehouse_page_grant_preserves_existing_linked_received_return_authorization(): void
    {
        $user = $this->actor(['page.warehouse.inbound_queue']);

        $this->assertTrue(SalesReturnVoidAccess::allowed($user, true));
        $this->assertFalse(SalesReturnVoidAccess::allowed($user, false));
    }

    public function test_administrator_keeps_void_access_without_direct_action_permission(): void
    {
        $admin = $this->actor([], true);

        $this->assertTrue(SalesReturnVoidAccess::allowed($admin, false));
        $this->assertTrue(SalesReturnVoidAccess::allowed($admin, true));
    }

    public function test_guest_cannot_void_returns(): void
    {
        $this->assertFalse(SalesReturnVoidAccess::allowed(null, false));
        $this->assertFalse(SalesReturnVoidAccess::allowed(null, true));
    }

    public function test_list_detail_and_post_action_use_the_same_authorization_rule(): void
    {
        $report = file_get_contents(app_path('Services/SalesReturnReportService.php'));
        $show = file_get_contents(resource_path('views/vouchers/return-from-sale/show.blade.php'));
        $controller = file_get_contents(app_path('Http/Controllers/VoucherSalesReturnController.php'));

        $this->assertStringContainsString('SalesReturnVoidAccess::allowed', $report);
        $this->assertStringContainsString('SalesReturnVoidAccess::allowed', $show);
        $this->assertStringContainsString('SalesReturnVoidAccess::allowed', $controller);
    }

    private function actor(array $permissions, bool $admin = false): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('isAdministrator')->andReturn($admin);
        $user->shouldReceive('hasRole')->andReturn(false);
        $user->shouldReceive('hasPermission')->andReturnUsing(
            static fn (string $permission): bool => in_array($permission, $permissions, true)
        );

        return $user;
    }
}
