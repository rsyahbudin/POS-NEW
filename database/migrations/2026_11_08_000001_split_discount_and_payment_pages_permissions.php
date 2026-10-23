<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Promos, payment methods and taxes moved out of Pengaturan into their own pages. Whoever could change them there
     * (the Pengaturan permission) keeps the right on the new pages.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $discounts = Permission::findOrCreate('manage-discount-presets', 'web');
        $pricing = Permission::findOrCreate('manage-pricing', 'web');
        Role::query()->where('guard_name', 'web')->get()
            ->filter(fn (Role $r) => $r->hasPermissionTo('manage-settings'))
            ->each(fn (Role $r) => $r->givePermissionTo([$discounts, $pricing]));
    }

    public function down(): void {}
};
