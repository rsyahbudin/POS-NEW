<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** Ramadan reservations: guests book and pre-order from the reservation menu, tables can be held together, and the joined tables share one bill. */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('reservation_menu')->default(false)->after('is_bundle');
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->string('public_id', 12)->unique();
            $table->string('name', 80);
            $table->string('phone', 30);
            $table->unsignedSmallInteger('pax');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 12)->default('booked'); // booked | seated | cancelled | no_show
            $table->string('note', 200)->nullable();
            $table->json('items');                            // what the guest pre-ordered, as sent: product, variant, options, qty
            $table->json('summary');                          // the same lines as the guest saw them: name, qty, total
            $table->bigInteger('items_total')->default(0);
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('seated_at')->nullable();
            $table->timestamps();

            $table->index(['outlet_id', 'starts_at']);
        });

        Schema::create('reservation_tables', function (Blueprint $table) {
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('table_id')->constrained()->cascadeOnDelete();
            $table->primary(['reservation_id', 'table_id']);
        });

        // The extra tables of an order that was opened on one table for a party seated at several: one order, one bill.
        Schema::create('order_tables', function (Blueprint $table) {
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('table_id')->constrained()->cascadeOnDelete();
            $table->primary(['order_id', 'table_id']);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::findOrCreate('manage-reservations', 'web');
        foreach (Role::query()->where('guard_name', 'web')->get() as $role) {
            if ($role->name === 'owner' || $role->permissions->contains('name', 'receive-payment')) {
                $role->givePermissionTo($permission);
            }
        }
    }

    public function down(): void
    {
        Permission::query()->where('name', 'manage-reservations')->delete();
        Schema::dropIfExists('order_tables');
        Schema::dropIfExists('reservation_tables');
        Schema::dropIfExists('reservations');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('reservation_menu'));
    }
};
