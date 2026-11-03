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
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_bundle')->default(false)->after('is_active');
        });

        Schema::create('bundle_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bundle_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedSmallInteger('qty')->default(1);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['bundle_id', 'product_id']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->json('bundle_snapshot')->nullable()->after('modifiers_snapshot');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::findOrCreate('manage-bundles', 'web');
        foreach (Role::query()->where('guard_name', 'web')->get() as $role) {
            if ($role->name === 'owner' || $role->permissions->contains('name', 'manage-menu')) {
                $role->givePermissionTo($permission);
            }
        }
    }

    public function down(): void
    {
        Permission::query()->where('name', 'manage-bundles')->delete();
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('bundle_snapshot'));
        Schema::dropIfExists('bundle_items');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('is_bundle'));
    }
};
