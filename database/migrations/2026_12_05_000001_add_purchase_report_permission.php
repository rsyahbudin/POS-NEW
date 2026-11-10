<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** Who may read the purchase report: the owner and whoever already manages stock or reads the waste report. */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::findOrCreate('view-purchase-report', 'web');
        foreach (Role::query()->where('guard_name', 'web')->get() as $role) {
            $names = $role->permissions->pluck('name');
            if ($role->name === 'owner' || $names->contains('manage-inventory') || $names->contains('view-waste-report')) {
                $role->givePermissionTo($permission);
            }
        }
    }

    public function down(): void
    {
        Permission::query()->where('name', 'view-purchase-report')->delete();
    }
};
