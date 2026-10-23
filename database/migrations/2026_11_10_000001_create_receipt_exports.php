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
        Schema::create('receipt_exports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedSmallInteger('year');
            $t->unsignedTinyInteger('month');
            $t->string('status', 10)->default('pending'); // pending | running | done | failed
            $t->unsignedInteger('receipts')->default(0);
            $t->unsignedBigInteger('total')->default(0);
            $t->unsignedBigInteger('size')->default(0);
            $t->string('path')->nullable();
            $t->string('error', 300)->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->index(['outlet_id', 'year', 'month']);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::findOrCreate('export-receipts', 'web');
        foreach (['owner', 'manager'] as $role) {
            Role::query()->where('name', $role)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_exports');
        Permission::query()->where('name', 'export-receipts')->delete();
    }
};
