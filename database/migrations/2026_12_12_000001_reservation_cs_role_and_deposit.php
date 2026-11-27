<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Reservations are handled by owner, manager and a customer-service role. Cashier, waiter and kitchen only work with
     * orders. A deposit becomes a payment on the bill when the party is seated.
     */
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('deposit_payment_id')->nullable()->after('changed_at')->constrained('payments')->nullOnDelete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $manage = Permission::findOrCreate('manage-reservations', 'web');
        $seat = Permission::findOrCreate('seat-reservations', 'web');

        foreach (['cashier', 'waiter', 'kitchen'] as $name) {
            Role::query()->where('guard_name', 'web')->where('name', $name)->first()?->revokePermissionTo($manage);
        }
        foreach (Role::query()->where('guard_name', 'web')->get() as $role) {
            if ($role->permissions->contains('name', 'manage-reservations')) {
                $role->givePermissionTo($seat);
            }
        }

        $cs = Role::findOrCreate('cs', 'web');
        $cs->givePermissionTo([$manage, $seat]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('reservations', fn (Blueprint $table) => $table->dropConstrainedForeignId('deposit_payment_id'));
        Permission::query()->where('name', 'seat-reservations')->delete();
        Role::query()->where('name', 'cs')->where('guard_name', 'web')->delete();
    }
};
