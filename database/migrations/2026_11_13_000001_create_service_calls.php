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
        Schema::create('service_calls', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $t->foreignId('table_id')->constrained('tables')->cascadeOnDelete();
            $t->string('reason', 20)->default('help');
            $t->string('status', 10)->default('pending'); // pending | handled
            $t->unsignedSmallInteger('repeats')->default(0);
            $t->timestamp('last_rang_at')->nullable();
            $t->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('handled_at')->nullable();
            $t->timestamps();
            $t->index(['outlet_id', 'status']);
            $t->index(['table_id', 'status']);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::findOrCreate('handle-calls', 'web');
        foreach (['owner', 'manager', 'cashier', 'waiter', 'kitchen'] as $role) {
            Role::query()->where('name', $role)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_calls');
        Permission::query()->where('name', 'handle-calls')->delete();
    }
};
