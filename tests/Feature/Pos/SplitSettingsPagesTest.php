<?php

use App\Domain\Pricing\Models\DiscountPreset;
use App\Domain\Settings\Models\Outlet;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

it('shows discounts and promos on their own page, with the discount list and menu choices', function () {
    DiscountPreset::create(['outlet_id' => (int) Outlet::query()->value('id'), 'name' => 'Staff 10%', 'type' => 'percent', 'value' => 1000, 'needs_pin' => false, 'is_active' => true]);

    $this->actingAs(userWithRole('owner'))->get('/admin/discounts')->assertOk()->assertInertia(
        fn ($p) => $p->component('admin/discounts')->has('discountPresets', 1)->has('promotions')->has('menuChoices.categories')->has('menuChoices.products'),
    );
});

it('shows payment methods and taxes on their own page', function () {
    $this->actingAs(userWithRole('owner'))->get('/admin/payment-settings')->assertOk()->assertInertia(
        fn ($p) => $p->component('admin/payments-tax')->has('paymentMethods')->has('cashAccounts')->has('taxRules'),
    );
});

it('keeps Pengaturan to system options only', function () {
    $this->actingAs(userWithRole('owner'))->get('/admin/settings')->assertOk()->assertInertia(
        fn ($p) => $p->component('admin/settings')->has('catalog')->missing('paymentMethods')->missing('taxRules')->missing('promotions')->missing('discountPresets'),
    );
});

it('guards each page with its own permission', function () {
    foreach (['/admin/discounts', '/admin/payment-settings'] as $url) {
        $this->actingAs(userWithRole('cashier'))->get($url)->assertForbidden();
        $this->actingAs(userWithRole('manager'))->get($url)->assertForbidden(); // managers do not edit these by default
    }

    Role::findByName('manager')->givePermissionTo('manage-pricing');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(userWithRole('manager'))->get('/admin/payment-settings')->assertOk();
    $this->actingAs(userWithRole('manager'))->get('/admin/discounts')->assertForbidden();
    $this->actingAs(userWithRole('manager'))->post('/admin/payment-methods', ['name' => 'X'])->assertSessionHasErrors(); // reaches validation, not 403
    $this->actingAs(userWithRole('manager'))->post('/admin/promotions', ['name' => 'X'])->assertForbidden();
});
