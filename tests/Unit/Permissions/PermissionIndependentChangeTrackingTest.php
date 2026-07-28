<?php

namespace Tests\Unit\Permissions;

use PHPUnit\Framework\TestCase;

class PermissionIndependentChangeTrackingTest extends TestCase
{
    public function test_frontend_tracks_role_and_direct_permission_snapshots_independently(): void
    {
        $script = file_get_contents(__DIR__.'/../../../public/js/permissions.js');

        $this->assertStringContainsString('initialRoles', $script);
        $this->assertStringContainsString('initialDirectPermissions', $script);
        $this->assertStringContainsString('serializeRoles', $script);
        $this->assertStringContainsString('serializeDirectPermissions', $script);
        $this->assertStringContainsString("rolesChanged.value = rolesDirty ? '1' : '0'", $script);
        $this->assertStringContainsString("directPermissionsChanged.value = permissionsDirty ? '1' : '0'", $script);
    }

    public function test_dependency_normalization_only_targets_direct_permission_inputs(): void
    {
        $script = file_get_contents(__DIR__.'/../../../public/js/permissions.js');

        $this->assertStringContainsString("checkbox.classList.contains('permission-check')", $script);
        $this->assertStringContainsString('permissionInputs.forEach', $script);
        $this->assertStringContainsString('roleInputs.forEach', $script);
    }

    public function test_backend_uses_changed_flags_and_preserves_legacy_ids(): void
    {
        $controller = file_get_contents(__DIR__.'/../../../app/Http/Controllers/Admin/UserPermissionController.php');
        $service = file_get_contents(__DIR__.'/../../../app/Services/Permissions/PermissionManagementService.php');

        $this->assertStringContainsString("boolean('roles_changed')", $controller);
        $this->assertStringContainsString("boolean('direct_permissions_changed')", $controller);
        $this->assertStringContainsString('$legacyPermissionIds', $service);
        $this->assertStringContainsString('array_merge(', $service);
    }
}
