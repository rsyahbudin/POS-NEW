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
        Schema::table('purchases', function (Blueprint $t) {
            $t->string('status', 10)->default('approved'); // pending | approved | rejected (existing rows are already applied)
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->string('review_note', 200)->nullable();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::findOrCreate('record-purchase', 'web');
        foreach (['owner', 'manager'] as $name) {
            Role::query()->where('name', $name)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        }
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $t) {
            $t->dropConstrainedForeignId('reviewed_by');
            $t->dropColumn(['status', 'reviewed_at', 'review_note']);
        });
        Permission::query()->where('name', 'record-purchase')->delete();
    }
};
