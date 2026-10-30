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
        'create-order', 'receive-payment', 'discount-within-limit', 'discount-custom', 'discount-over-limit', 'void', 'refund',
        'open-close-shift', 'manage-shifts-any', 'update-kitchen-status', 'input-expense',
        'view-sales-reports', 'view-own-shift-recap', 'view-accounting', 'manage-accounting',
        'cancel-order', 'use-print-station', 'view-kitchen-report', 'view-margin-report', 'manage-printers',
        'view-stock', 'count-stock', 'view-assets', 'manage-assets', 'import-data', 'record-purchase', 'view-activity-log', 'manage-suppliers', 'record-waste', 'view-waste-report', 'manage-online-payments', 'export-receipts', 'mark-sold-out', 'view-today-summary', 'reopen-shift', 'handle-calls', 'view-cash-ledger',
        'manage-menu', 'manage-inventory', 'manage-pricing', 'manage-discount-presets', 'manage-users', 'manage-settings', 'manage-tables', 'manage-roles',
    ];

    public const ROLES = [
        'manager' => [
            'create-order', 'receive-payment', 'discount-within-limit', 'discount-custom', 'discount-over-limit', 'void', 'refund',
            'open-close-shift', 'manage-shifts-any', 'update-kitchen-status', 'input-expense',
            'view-sales-reports', 'view-own-shift-recap', 'view-accounting', 'manage-menu', 'manage-inventory', 'manage-tables',
            'cancel-order', 'use-print-station', 'view-kitchen-report', 'view-margin-report',
            'view-stock', 'count-stock', 'view-assets', 'manage-assets', 'import-data', 'record-purchase', 'view-activity-log', 'manage-suppliers', 'record-waste', 'view-waste-report', 'manage-online-payments', 'export-receipts', 'mark-sold-out', 'view-today-summary', 'reopen-shift', 'handle-calls', 'view-cash-ledger',
        ],
        'cashier' => ['handle-calls', 'reopen-shift', 'view-today-summary', 'create-order', 'receive-payment', 'discount-within-limit', 'open-close-shift', 'update-kitchen-status', 'view-own-shift-recap', 'cancel-order', 'use-print-station', 'view-stock', 'count-stock', 'view-assets'],
        'waiter' => ['handle-calls', 'view-today-summary', 'create-order', 'cancel-order', 'use-print-station', 'view-stock', 'count-stock', 'view-assets'],
        'kitchen' => ['handle-calls', 'view-today-summary', 'update-kitchen-status', 'use-print-station', 'view-stock', 'count-stock', 'view-assets'],
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
