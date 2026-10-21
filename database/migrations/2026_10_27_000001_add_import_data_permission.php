<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::findOrCreate('import-data', 'web');
        foreach (Role::query()->where('guard_name', 'web')->get() as $role) {
            if ($role->name === 'owner' || $role->hasAnyPermission(['manage-inventory', 'manage-menu', 'manage-assets'])) {
                $role->givePermissionTo($permission);
            }
        }
    }

    public function down(): void
    {
        Permission::query()->where('name', 'import-data')->delete();
    }
};
