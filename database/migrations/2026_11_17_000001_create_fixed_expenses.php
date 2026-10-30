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
        Schema::create('fixed_expenses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained('outlets');
            $t->string('name', 80);
            $t->foreignId('expense_category_id')->constrained('expense_categories');
            $t->unsignedBigInteger('amount')->default(0);
            $t->unsignedTinyInteger('due_day');
            $t->foreignId('source_account_id')->nullable()->constrained('accounts');
            $t->string('note', 200)->nullable();
            $t->date('starts_on');
            $t->date('ends_on')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('fixed_expense_bills', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained('outlets');
            $t->foreignId('fixed_expense_id')->constrained('fixed_expenses')->cascadeOnDelete();
            $t->date('period');
            $t->date('due_date');
            $t->unsignedBigInteger('amount');
            $t->string('status', 10)->default('unpaid');
            $t->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $t->date('paid_on')->nullable();
            $t->foreignId('settled_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['fixed_expense_id', 'period']);
            $t->index(['outlet_id', 'period']);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::findOrCreate('manage-fixed-expenses', 'web');
        foreach (['owner', 'manager'] as $role) {
            Role::query()->where('name', $role)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        }
    }

    public function down(): void
    {
        Permission::query()->where('name', 'manage-fixed-expenses')->delete();
        Schema::dropIfExists('fixed_expense_bills');
        Schema::dropIfExists('fixed_expenses');
    }
};
