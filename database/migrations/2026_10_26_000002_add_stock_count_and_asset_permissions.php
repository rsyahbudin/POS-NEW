<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** permission => built-in roles that get it by default (owner always) */
    private const DEFAULTS = [
        'view-stock' => ['manager', 'cashier', 'waiter', 'kitchen'],
        'count-stock' => ['manager', 'cashier', 'waiter', 'kitchen'],
        'view-assets' => ['manager', 'cashier', 'waiter', 'kitchen'],
        'manage-assets' => ['manager'],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::DEFAULTS as $name => $roles) {
            $permission = Permission::findOrCreate($name, 'web');
            foreach (array_merge(['owner'], $roles) as $role) {
                Role::query()->where('name', $role)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
            }
        }
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', array_keys(self::DEFAULTS))->delete();
    }
};
