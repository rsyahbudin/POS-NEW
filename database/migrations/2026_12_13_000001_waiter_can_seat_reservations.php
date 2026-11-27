<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** The waiter at the door marks a reservation as arrived (seats the party); everything else stays with customer service. */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $seat = Permission::findOrCreate('seat-reservations', 'web');
        Role::query()->where('guard_name', 'web')->where('name', 'waiter')->first()?->givePermissionTo($seat);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::query()->where('guard_name', 'web')->where('name', 'waiter')->first()?->revokePermissionTo('seat-reservations');
    }
};
