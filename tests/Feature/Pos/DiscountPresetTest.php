<?php

use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Pricing\Actions\ApplyDiscountAction;
use App\Domain\Pricing\Models\DiscountPreset;
use App\Domain\Settings\Models\Outlet;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function preset(string $name, string $type, int $value, bool $pin = false): DiscountPreset
{
    return DiscountPreset::create(['outlet_id' => (int) Outlet::query()->value('id'), 'name' => $name, 'type' => $type, 'value' => $value, 'needs_pin' => $pin, 'is_active' => true]);
}

function revoke(string $role, string $permission): void
{
    Role::findByName($role)->revokePermissionTo($permission);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

it('lets a cashier apply a registered discount in one tap, even above the manual limit, with the preset as the reason', function () {
    $order = placeOrder(); // 44.000
    $owner20 = preset('Owner 20%', 'percent', 2000);
    $cashier = userWithRole('cashier');

    $d = app(ApplyDiscountAction::class)->preset($order, $cashier, $owner20);

    expect($d->amount)->toBe(8800)->and($d->reason)->toBe('Owner 20%')->and($d->preset_id)->toBe($owner20->id)->and($d->approved_by)->toBe($cashier->id);
    expect($order->fresh()->grand_total)->toBe(35200);
    expect(Activity::query()->where('event', 'discount.applied')->latest('id')->first()?->properties['preset'])->toBe('Owner 20%');
});

it('asks for a manager PIN when the registered discount is marked that way', function () {
    $order = placeOrder();
    $vip = preset('VIP 30%', 'percent', 3000, pin: true);
    $svc = app(ApplyDiscountAction::class);

    expect(fn () => $svc->preset($order, userWithRole('cashier'), $vip))->toThrow(OrderException::class, 'PIN');
    $d = $svc->preset($order, userWithRole('cashier'), $vip, pin: '222222');
    expect($d->approved_by)->toBe(userWithRole('manager')->id);
});

it('refuses inactive or foreign discounts', function () {
    $order = placeOrder();
    $off = preset('Lama', 'percent', 1000);
    $off->update(['is_active' => false]);

    expect(fn () => app(ApplyDiscountAction::class)->preset($order, userWithRole('cashier'), $off))->toThrow(OrderException::class, 'tidak tersedia');
});

it('blocks typing a discount yourself once the cashier role loses the manual permission, but presets still work', function () {
    revoke('cashier', 'discount-custom');
    $order = placeOrder();
    $cashier = userWithRole('cashier');
    $svc = app(ApplyDiscountAction::class);

    expect(fn () => $svc->handle($order, $cashier, 'percent', 500, 'bebas'))->toThrow(OrderException::class, 'manual');
    $svc->preset($order, $cashier, preset('Staff 10%', 'percent', 1000));
    expect($order->fresh()->discount_total)->toBe(4400);
});

it('serves the discount list to the POS and applies a preset over HTTP', function () {
    openShiftFor(userWithRole('cashier'), 0);
    preset('Staff 10%', 'percent', 1000);
    $cashier = userWithRole('cashier');
    $order = placeOrder($cashier);

    $this->actingAs($cashier)->get('/pos')->assertInertia(fn ($p) => $p->where('can.discount_custom', true)->has('discountPresets', 1)->where('discountPresets.0.name', 'Staff 10%'));
    $id = DiscountPreset::query()->value('id');
    $this->actingAs($cashier)->postJson("/pos/orders/{$order->id}/discount-presets/{$id}")->assertOk()->assertJsonPath('order.discount_total', 4400);

    revoke('cashier', 'discount-custom');
    $this->actingAs($cashier)->postJson("/pos/orders/{$order->id}/discounts", ['type' => 'percent', 'value' => 500, 'reason' => 'x'])->assertUnprocessable();
});

it('lets only the owner manage the list, in percent or rupiah', function () {
    $owner = userWithRole('owner');
    $this->actingAs($owner)->post('/admin/discount-presets', ['name' => 'Owner 20%', 'type' => 'percent', 'value' => 20])->assertSessionHasNoErrors();
    $this->actingAs($owner)->post('/admin/discount-presets', ['name' => 'Voucher 5rb', 'type' => 'fixed', 'value' => 5000, 'needs_pin' => true])->assertSessionHasNoErrors();
    $this->actingAs($owner)->post('/admin/discount-presets', ['name' => 'Owner 20%', 'type' => 'percent', 'value' => 20])->assertSessionHasErrors('name');
    $this->actingAs($owner)->post('/admin/discount-presets', ['name' => 'Gila', 'type' => 'percent', 'value' => 150])->assertSessionHasErrors('value');

    expect(DiscountPreset::query()->where('name', 'Owner 20%')->value('value'))->toBe(2000)->and(DiscountPreset::query()->where('name', 'Voucher 5rb')->value('value'))->toBe(5000);
    $id = DiscountPreset::query()->where('name', 'Owner 20%')->value('id');
    $this->actingAs($owner)->put("/admin/discount-presets/{$id}", ['name' => 'Owner 20%', 'type' => 'percent', 'value' => 20, 'is_active' => false])->assertSessionHasNoErrors();
    expect(DiscountPreset::query()->find($id)->is_active)->toBeFalse();

    $this->actingAs(userWithRole('manager'))->post('/admin/discount-presets', ['name' => 'X', 'type' => 'percent', 'value' => 5])->assertForbidden();
    $this->actingAs(userWithRole('cashier'))->post('/admin/discount-presets', ['name' => 'X', 'type' => 'percent', 'value' => 5])->assertForbidden();
});
