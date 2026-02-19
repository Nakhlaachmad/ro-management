<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // =====================
        // PERMISSIONS
        // =====================

        $permissions = [

            // User Management
            'view users',
            'create users',
            'edit users',
            'delete users',

            // Team Management
            'view teams',
            'create teams',
            'edit teams',
            'delete teams',

            // FP Management
            'view fp',
            'create fp',
            'edit fp',
            'delete fp',
            'change status fp',

            // Comment FP Keluar
            'add comment fp keluar',

            // Dashboard
            'view dashboard',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // =====================
        // ROLES
        // =====================

        $admin = Role::firstOrCreate(['name' => 'admin']);
        $ro = Role::firstOrCreate(['name' => 'ro']);
        $hrd = Role::firstOrCreate(['name' => 'hrd']);

        // =====================
        // ASSIGN PERMISSION
        // =====================

        // Admin → semua permission
        $admin->syncPermissions(Permission::all());

        // RO
        $ro->syncPermissions([
            'view fp',
            'create fp',
            'edit fp',
            'change status fp',
            'add comment fp keluar',
            'view dashboard',
        ]);

        // HRD
        $hrd->syncPermissions([
            'view dashboard',
        ]);
    }
}
