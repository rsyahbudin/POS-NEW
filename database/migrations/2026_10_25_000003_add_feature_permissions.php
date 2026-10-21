<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Features that used to piggy-back on other permissions get their own, so each can be set per role.
 * Existing roles keep what they could already do: a role that had the old permission gets the new one.
 */
return new class extends Migration
{
    /** new permission => permissions that used to allow it (any of) */
    private const MAP = [
        'manage-printers' => ['manage-settings'],
        'use-print-station' => ['receive-payment', 'create-order', 'update-kitchen-status', 'manage-settings'],
        'view-kitchen-report' => ['view-sales-reports'],
        'view-margin-report' => ['view-sales-reports'],
        'cancel-order' => ['create-order'],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::MAP as $new => $old) {
            $permission = Permission::findOrCreate($new, 'web');
            foreach (Role::query()->where('guard_name', 'web')->get() as $role) {
                if ($role->name === 'owner' || $role->hasAnyPermission($old)) {
                    $role->givePermissionTo($permission);
                }
            }
        }
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', array_keys(self::MAP))->delete();
    }
};
