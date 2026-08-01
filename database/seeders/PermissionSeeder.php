<?php

namespace Database\Seeders;

use App\Support\PermissionCatalog;
use App\Support\PageAccessCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionCatalog::all() as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $permission['key']],
                [
                    'name' => $permission['name'],
                    'group' => $permission['group'],
                    'guard_name' => 'web',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        foreach (PageAccessCatalog::pages() as $page) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $page['permission']],
                ['name' => $page['label'], 'group' => $page['group'], 'guard_name' => 'web', 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }
}
