<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_shift_types', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained('outlets');
            $t->string('name', 40);
            $t->time('starts_at');
            $t->time('ends_at');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('work_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained('outlets');
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->date('work_date');
            $t->foreignId('work_shift_type_id')->constrained('work_shift_types')->cascadeOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['user_id', 'work_date', 'work_shift_type_id']);
            $t->index(['outlet_id', 'work_date']);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $view = Permission::findOrCreate('view-schedule', 'web');
        $manage = Permission::findOrCreate('manage-schedule', 'web');
        foreach (['owner', 'manager', 'cashier', 'waiter', 'kitchen'] as $role) {
            Role::query()->where('name', $role)->where('guard_name', 'web')->first()?->givePermissionTo($view);
        }
        foreach (['owner', 'manager'] as $role) {
            Role::query()->where('name', $role)->where('guard_name', 'web')->first()?->givePermissionTo($manage);
        }
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', ['view-schedule', 'manage-schedule'])->delete();
        Schema::dropIfExists('work_schedules');
        Schema::dropIfExists('work_shift_types');
    }
};
