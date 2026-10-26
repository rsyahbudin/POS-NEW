<?php

use App\Domain\Catalog\Models\Product;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Settings\Models\Outlet;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

it('sets up a fresh installation with one owner, no sample data and no default password', function () {
    $this->artisan('pos:install', ['--outlet' => 'Kopi Tetangga', '--address' => 'Jl. Mawar 3', '--name' => 'Budi', '--email' => 'budi@kopitetangga.id', '--password' => 'rahasia-panjang-123'])->assertSuccessful();

    $owner = User::query()->sole();
    expect($owner->email)->toBe('budi@kopitetangga.id')->and($owner->hasRole('owner'))->toBeTrue()->and(Hash::check('rahasia-panjang-123', $owner->password))->toBeTrue()
        ->and(Hash::check('password', $owner->password))->toBeFalse()->and($owner->pin_hash)->toBeNull();
    expect(Outlet::query()->sole()->name)->toBe('Kopi Tetangga')->and(PaymentMethod::query()->where('name', 'Tunai')->exists())->toBeTrue();
    expect(Product::query()->count())->toBe(0)->and(Role::query()->whereIn('name', ['manager', 'cashier', 'waiter', 'kitchen'])->count())->toBe(4);
    expect($owner->can('manage-roles'))->toBeTrue();
});

it('refuses to run again once there are users, and refuses weak passwords', function () {
    $this->artisan('pos:install', ['--outlet' => 'A', '--name' => 'B', '--email' => 'b@x.id', '--password' => 'pendek'])->assertFailed();
    expect(User::query()->count())->toBe(0);

    $this->artisan('pos:install', ['--outlet' => 'A', '--name' => 'B', '--email' => 'b@x.id', '--password' => 'cukup-panjang-123'])->assertSuccessful();
    $this->artisan('pos:install', ['--outlet' => 'C', '--name' => 'D', '--email' => 'd@x.id', '--password' => 'cukup-panjang-456'])->assertFailed();
    expect(User::query()->count())->toBe(1);
});
