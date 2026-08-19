<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([PermissionSeeder::class, RoleSeeder::class]);

        foreach (['dashboard.view', 'customers.view', 'customers.create', 'customers.update', 'customers.delete'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $projectManager = Role::findOrCreate('project-manager', 'web');
        $projectManager->givePermissionTo([
            'customers.view', 'customers.create', 'customers.update', 'customers.delete',
        ]);
        Role::findOrCreate('team-member', 'web');
        Role::findOrCreate('super-admin', 'web');
    }
}
