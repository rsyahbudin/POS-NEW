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

        // Free-form discounts keep working for everyone who could already give a discount; the owner can take it
        // away from a role (e.g. cashier) so that role only picks registered discounts.
        $custom = Permission::findOrCreate('discount-custom', 'web');
        Role::query()->where('guard_name', 'web')->get()
            ->filter(fn (Role $r) => $r->hasPermissionTo('discount-within-limit'))
            ->each(fn (Role $r) => $r->givePermissionTo($custom));

        $manage = Permission::findOrCreate('manage-discount-presets', 'web');
        // The list is edited in Pengaturan, which only the owner opens by default.
        Role::query()->where('name', 'owner')->where('guard_name', 'web')->first()?->givePermissionTo($manage);
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', ['discount-custom', 'manage-discount-presets'])->delete();
    }
};
