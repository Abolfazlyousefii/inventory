<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $roles = [
            'super_admin' => PermissionCatalog::activeKeys(),
            'system_admin' => ['dashboard.view','users.view','users.create','users.edit','users.change_password','users.change_status','users.sync','roles.view','roles.create','roles.edit','roles.assign_permissions','permissions.view','permissions.edit','permissions.assign_roles','permissions.sync','settings.view','settings.edit','settings.backup','settings.restore','logs.view','inventory_webhooks.view','inventory_webhooks.edit'],
            'sales_user' => ['dashboard.view','products.view','products.show','customers.view','customers.create','customers.edit','preinvoices.create','preinvoices.own.view','preinvoices.drafts.view','preinvoices.drafts.edit','preinvoices.print'],
            'sales_manager' => ['dashboard.view','products.view','products.show','customers.view','customers.create','customers.edit','customers.export','preinvoices.create','preinvoices.own.view','preinvoices.drafts.view','preinvoices.drafts.edit','preinvoices.print','preinvoices.all.view','reports.customers'],
            'accountant' => ['dashboard.view','invoices.view','invoices.show','invoices.print','payments.view','payments.create','cheques.view','cheques.create','account_statements.view','finance.reports.view'],
            'finance_manager' => ['dashboard.view','invoices.view','invoices.show','invoices.print','invoices.edit','invoices.cancel','invoices.change_status','payments.view','payments.create','cheques.view','cheques.create','account_statements.view','finance.reports.view','preinvoices.finance.view','preinvoices.finance.confirm','preinvoices.finance.cancel'],
            'warehouse_operator' => ['dashboard.view','products.view','products.show','warehouse.collection.queue.view','warehouse.collection.view','warehouse.collection.receive','warehouse.collection.start','warehouse.collection.edit','warehouse.collection.submit_reapproval','warehouse.shipping.queue.view','warehouse.shipping.view','warehouse.shipping.ship'],
            'warehouse_manager' => ['dashboard.view','products.view','products.show','warehouse.collection.queue.view','warehouse.collection.view','warehouse.collection.receive','warehouse.collection.start','warehouse.collection.edit','warehouse.collection.submit_reapproval','warehouse.collection.adjust_price','warehouse.shipping.queue.view','warehouse.shipping.view','warehouse.shipping.ship','inventory.view','inventory.adjust','inventory.count.view','warehouse_map.view','transfers.view','sales_returns.view'],
            'purchasing_user' => ['dashboard.view','products.view','products.show','stock_in.view','stock_in.create','stock_in.edit','stock_in.print','suppliers.view','suppliers.create','suppliers.edit'],
            'auditor' => collect(PermissionCatalog::registry())->reject('deprecated')->filter(fn (array $permission): bool => in_array($permission['action'], ['view','show','print','export'], true))->pluck('key')->all(),
            'admin' => collect(PermissionCatalog::all())->pluck('key')->reject(fn (string $key): bool => in_array($key, ['roles.delete'], true))->values()->all(),
            'staff' => ['dashboard.view', 'products.view', 'inventory.view', 'stock_in.view', 'stock_out.view', 'issues.view'],
            'editor' => ['dashboard.view', 'products.view', 'products.edit', 'products.export', 'reports.products'],
            'union_expert' => ['dashboard.view', 'customers.view', 'preinvoices.own.view', 'tickets.view', 'tickets.reply'],
            'user' => ['dashboard.view', 'preinvoices.own.view'],
            'employee' => ['dashboard.view', 'users.view'],
        ];

        foreach ($roles as $roleName => $permissionKeys) {
            $role = Role::findOrCreate($roleName, 'web');

            $permissionIds = DB::table('permissions')
                ->whereIn('key', $permissionKeys)
                ->pluck('id')
                ->all();

            foreach ($permissionIds as $permissionId) {
                DB::table('role_has_permissions')->updateOrInsert([
                    'role_id' => $role->id,
                    'permission_id' => $permissionId,
                ]);
            }
        }

        // Compatibility is additive: legacy roles and their existing assignments are never removed.
        foreach (PermissionCatalog::roleAliases() as $standard => $aliases) {
            $preset = $roles[$standard] ?? [];
            $permissionIds = DB::table('permissions')->whereIn('key', $preset)->pluck('id');
            foreach ($aliases as $alias) {
                $legacyRole = Role::query()->where('name', $alias)->where('guard_name', 'web')->first();
                if (! $legacyRole || $legacyRole->name === $standard) {
                    continue;
                }
                foreach ($permissionIds as $permissionId) {
                    DB::table('role_has_permissions')->updateOrInsert(['role_id' => $legacyRole->id, 'permission_id' => $permissionId]);
                }
            }
        }

        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Admin', 'password' => Hash::make('Admin@12345'), 'is_active' => true]
        );

        if (! $admin->hasAnyRole(['super_admin', 'admin'])) {
            $admin->assignRole('super_admin');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
