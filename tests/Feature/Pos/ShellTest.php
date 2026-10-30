<?php

use App\Support\PermissionCatalog;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

it('puts back-office people in the sidebar shell and floor staff, MOD included, in the top bar shell', function () {
    $shell = fn (string $role) => $this->actingAs(userWithRole($role))->get('/today')->viewData('page')['props']['shell'];

    expect($shell('owner'))->toBe('office')->and($shell('manager'))->toBe('office')
        ->and($shell('cashier'))->toBe('floor')->and($shell('waiter'))->toBe('floor')->and($shell('kitchen'))->toBe('floor')->and($shell('mod'))->toBe('floor');
});

it('follows permissions for custom roles: one office right is enough for the sidebar', function () {
    $role = Role::create(['name' => 'Admin gudang', 'guard_name' => 'web']);
    $role->givePermissionTo(['view-stock', 'manage-inventory', 'view-today-summary']);
    $user = userWithRole('waiter');
    $user->syncRoles([$role]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(PermissionCatalog::shellFor($user->fresh()))->toBe('office');
    $role->revokePermissionTo('manage-inventory');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect(PermissionCatalog::shellFor($user->fresh()))->toBe('floor');
});

it('sends each role to its own work screen, and the Layar kerja button goes the same place', function () {
    $home = fn (string $role) => $this->actingAs(userWithRole($role))->get('/today')->viewData('page')['props']['home'];

    expect($home('owner'))->toBe('/dashboard')->and($home('manager'))->toBe('/dashboard')->and($home('cashier'))->toBe('/pos')->and($home('waiter'))->toBe('/pos')->and($home('mod'))->toBe('/pos');
    setting('kitchen.enabled', false);
    expect($home('kitchen'))->toBe('/stock');          // kitchen screen is off: no dead link
    setting('kitchen.enabled', true);
    expect($home('kitchen'))->toBe('/kds');

    $this->actingAs(userWithRole('cashier'))->get('/')->assertRedirect('/pos');
    $this->actingAs(userWithRole('owner'))->get('/')->assertRedirect('/dashboard');
    $this->actingAs(userWithRole('kitchen'))->get('/')->assertRedirect('/kds');
});
