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
        $permission = Permission::findOrCreate('view-own-attendance', 'web');
        foreach (['owner', 'manager', 'mod', 'cashier', 'waiter', 'kitchen'] as $role) {
            Role::query()->where('name', $role)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        }
    }

    public function down(): void
    {
        Permission::query()->where('name', 'view-own-attendance')->delete();
    }
};
