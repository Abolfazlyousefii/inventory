<?php
namespace Tests\Feature;
use App\Support\PermissionCatalog;
use Tests\TestCase;
class SalesReturnPermissionTest extends TestCase { public function test_sales_return_routes_are_mapped_to_permissions(): void { $this->assertSame('sales_returns.apply', PermissionCatalog::routePermissions()['sales-returns.apply']); $this->assertSame('sales_returns.create', PermissionCatalog::routePermissions()['sales-returns.store']); } }
