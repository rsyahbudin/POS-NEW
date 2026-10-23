<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** Owner's decision: cashier and waiter only pick registered discounts. The permission can be given back in Peran & Akses. */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $custom = Permission::query()->where('name', 'discount-custom')->where('guard_name', 'web')->first();
        if ($custom === null) {
            return;
        }
        Role::query()->whereIn('name', ['cashier', 'waiter'])->where('guard_name', 'web')->get()->each(fn (Role $r) => $r->revokePermissionTo($custom));
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $custom = Permission::findOrCreate('discount-custom', 'web');
        Role::query()->where('name', 'cashier')->where('guard_name', 'web')->first()?->givePermissionTo($custom);
    }
};
