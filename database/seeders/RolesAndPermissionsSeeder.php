<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Permission matrix from PRD section 3. "PIN manager" cases are not granted to cashier on purpose. */
class RolesAndPermissionsSeeder extends Seeder
{
    public const ALL = [
        'create-order', 'receive-payment', 'discount-within-limit', 'discount-over-limit', 'void', 'refund',
        'open-close-shift', 'manage-shifts-any', 'update-kitchen-status', 'input-expense',
        'view-sales-reports', 'view-own-shift-recap', 'view-accounting', 'manage-accounting',
        'manage-menu', 'manage-inventory', 'manage-pricing', 'manage-users', 'manage-settings', 'manage-tables', 'manage-roles',
    ];

    public const ROLES = [
        'manager' => [
            'create-order', 'receive-payment', 'discount-within-limit', 'discount-over-limit', 'void', 'refund',
            'open-close-shift', 'manage-shifts-any', 'update-kitchen-status', 'input-expense',
            'view-sales-reports', 'view-own-shift-recap', 'view-accounting', 'manage-menu', 'manage-inventory', 'manage-tables',
        ],
        'cashier' => ['create-order', 'receive-payment', 'discount-within-limit', 'open-close-shift', 'update-kitchen-status', 'view-own-shift-recap'],
        'waiter' => ['create-order'],
        'kitchen' => ['update-kitchen-status'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::ALL as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Owner always holds everything. Other roles get defaults only when first created,
        // so permissions edited in the app (Peran & Akses) survive a re-seed.
        Role::findOrCreate('owner', 'web')->syncPermissions(self::ALL);
        foreach (self::ROLES as $role => $permissions) {
            $model = Role::findOrCreate($role, 'web');
            if ($model->wasRecentlyCreated) {
                $model->syncPermissions($permissions);
                $model->forceFill(['allow_pin_login' => in_array($role, ['cashier', 'waiter', 'kitchen'], true)])->save();
            }
        }
    }
}
