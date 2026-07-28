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

    public function test_permission_assets_are_cache_busted_and_catalog_version_is_submitted(): void
    {
        $view = file_get_contents(__DIR__.'/../../../resources/views/admin/permissions/index.blade.php');

        $this->assertStringContainsString('filemtime(', $view);
        $this->assertStringContainsString('permissions.css', $view);
        $this->assertStringContainsString('permissions.js', $view);
        $this->assertStringContainsString('permission_catalog_version', $view);
        $this->assertStringContainsString('PermissionCatalog::versionHash()', $view);
    }

    public function test_self_healing_is_guarded_by_direct_permission_change_and_logs_failure(): void
    {
        $service = file_get_contents(__DIR__.'/../../../app/Services/Permissions/PermissionManagementService.php');

        $this->assertStringContainsString('if ($changePermissions)', $service);
        $this->assertStringContainsString('ensurePermissionCatalogIsSynced', $service);
        $this->assertStringContainsString('PermissionCatalog::syncToDatabase()', $service);
        $this->assertStringContainsString('Permission catalog synchronization failed', $service);
        $this->assertStringContainsString("'missing_keys'", $service);
    }
}
