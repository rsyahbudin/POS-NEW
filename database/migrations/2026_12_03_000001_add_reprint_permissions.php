<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** Reprinting a receipt copy or an order ticket no longer needs the right to take payments, so waiters can do it. */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $receipt = Permission::findOrCreate('reprint-receipt', 'web');
        $ticket = Permission::findOrCreate('reprint-ticket', 'web');
        foreach (Role::query()->where('guard_name', 'web')->get() as $role) {
            $names = $role->permissions->pluck('name');
            if ($role->name === 'owner' || $names->contains('receive-payment') || $names->contains('create-order')) {
                $role->givePermissionTo([$receipt, $ticket]);
            }
        }
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', ['reprint-receipt', 'reprint-ticket'])->delete();
    }
};
