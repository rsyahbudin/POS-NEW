<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** Which station holds which ingredient, and which station a staff member works for, so each station counts and buys its own stock. */
    public function up(): void
    {
        Schema::create('ingredient_station', function (Blueprint $table) {
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->primary(['ingredient_id', 'station_id']);
        });

        Schema::create('station_user', function (Blueprint $table) {
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['station_id', 'user_id']);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::findOrCreate('stock-all-stations', 'web');
        foreach (Role::query()->where('guard_name', 'web')->get() as $role) {
            if ($role->name === 'owner' || $role->permissions->contains('name', 'manage-inventory')) {
                $role->givePermissionTo($permission);
            }
        }
    }

    public function down(): void
    {
        Permission::query()->where('name', 'stock-all-stations')->delete();
        Schema::dropIfExists('station_user');
        Schema::dropIfExists('ingredient_station');
    }
};
