<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const NEW = ['manage-online-payments'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::NEW as $name) {
            $permission = Permission::findOrCreate($name, 'web');
            foreach (['owner', 'manager'] as $role) {
                Role::query()->where('name', $role)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
            }
        }
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', self::NEW)->delete();
    }
};
