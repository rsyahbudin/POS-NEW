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
        Schema::table('cash_movements', function (Blueprint $t) {
            $t->foreignId('expense_category_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $t->string('receipt_path')->nullable();
        });
        Schema::table('expenses', function (Blueprint $t) {
            $t->foreignId('cash_movement_id')->nullable()->unique()->constrained('cash_movements')->nullOnDelete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::findOrCreate('view-cash-ledger', 'web');
        foreach (['owner', 'manager'] as $role) {
            Role::query()->where('name', $role)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        }
    }

    public function down(): void
    {
        Permission::query()->where('name', 'view-cash-ledger')->delete();
        Schema::table('expenses', function (Blueprint $t) {
            $t->dropConstrainedForeignId('cash_movement_id');
        });
        Schema::table('cash_movements', function (Blueprint $t) {
            $t->dropConstrainedForeignId('expense_category_id');
            $t->dropColumn('receipt_path');
        });
    }
};
