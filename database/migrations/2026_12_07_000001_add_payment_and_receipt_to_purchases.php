<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** A purchase can carry the receipt photo and say where the money came from (drawer or own pocket). */
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $t) {
            $t->string('paid_from', 10)->nullable()->after('status');
            $t->string('receipt_path')->nullable()->after('paid_from');
            $t->foreignId('cash_movement_id')->nullable()->after('receipt_path')->constrained('cash_movements')->nullOnDelete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::findOrCreate('pay-purchase-from-drawer', 'web');
        foreach (Role::query()->where('guard_name', 'web')->get() as $role) {
            $names = $role->permissions->pluck('name');
            if ($role->name === 'owner' || ($names->contains('record-purchase') && $names->contains('open-close-shift'))) {
                $role->givePermissionTo($permission);
            }
        }
    }

    public function down(): void
    {
        Schema::table('purchases', fn (Blueprint $t) => $t->dropConstrainedForeignId('cash_movement_id'));
        Schema::table('purchases', fn (Blueprint $t) => $t->dropColumn(['paid_from', 'receipt_path']));
        Permission::query()->where('name', 'pay-purchase-from-drawer')->delete();
    }
};
