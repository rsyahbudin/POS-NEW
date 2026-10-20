<?php

use App\Domain\Catalog\Models\Station;
use App\Domain\Floor\Models\Table;
use App\Domain\Ordering\Actions\SubmitQrOrderAction;
use App\Domain\Ordering\Enums\RoundStatus;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Settings\Models\Outlet;
use App\Domain\Settings\SettingsCatalog;
use App\Domain\Settings\SettingsRepository;
use App\Http\Presenters\ReceiptPresenter;
use App\Support\ApprovalGate;
use Spatie\Activitylog\Models\Activity;

function qrOrder(int $qty = 1)
{
    return app(SubmitQrOrderAction::class)->handle(Table::query()->first()->qr_token, [
        'customer_name' => 'Rani', 'items' => [['product_id' => product('Americano')->id, 'qty' => $qty]],
    ]);
}

it('keeps QR orders pending for the cashier by default', function () {
    $round = qrOrder();
    expect($round->status)->toBe(RoundStatus::Pending);
});

it('sends QR orders straight to the kitchen when auto-confirm is on', function () {
    setting('qr.auto_confirm', true);
    $round = qrOrder(2);

    expect($round->status)->toBe(RoundStatus::Confirmed)
        ->and($round->confirmed_by)->toBeNull()
        ->and($round->items()->pluck('status')->map->value->unique()->all())->toBe(['new'])
        ->and($round->order->fresh()->subtotal)->toBe(44000);

    $tickets = $this->actingAs(userWithRole('kitchen'))->getJson('/kds/'.Station::query()->value('id').'/tickets')->json('tickets');
    expect($tickets)->toHaveCount(1);
    expect(Activity::query()->where('event', 'qr.confirmed')->first()->properties['auto'])->toBeTrue();
});

it('tells the QR page whether the cashier must confirm', function () {
    $token = Table::query()->first()->qr_token;
    $this->get("/m/{$token}")->assertInertia(fn ($p) => $p->where('autoConfirm', false));
    setting('qr.auto_confirm', true);
    $this->get("/m/{$token}")->assertInertia(fn ($p) => $p->where('autoConfirm', true));
});

it('exposes every setting in the UI catalog with validation and defaults', function () {
    $this->actingAs(userWithRole('owner'))->get('/admin/settings')->assertInertia(fn ($p) => $p->has('catalog', 6));

    foreach (SettingsCatalog::fields() as $key => $field) {
        expect($field)->toHaveKeys(['label', 'hint', 'type', 'default']);
        expect(app(SettingsRepository::class)->get($key))->toBe($field['default']);
    }
});

it('saves settings from the UI with proper types and rejects bad values', function () {
    $owner = userWithRole('owner');
    $this->actingAs($owner)->post('/admin/settings', ['values' => [
        'qr.auto_confirm' => true, 'qr.window_minutes' => '15', 'pos.quick_cash' => '20000, 50000,100000',
        'pricing.rounding' => '100', 'security.pin_max_attempts' => '3', 'hack.unknown' => 'x',
    ]])->assertSessionHasNoErrors();

    $s = app(SettingsRepository::class);
    expect($s->get('qr.auto_confirm'))->toBeTrue()->and($s->get('qr.window_minutes'))->toBe(15)
        ->and($s->get('pos.quick_cash'))->toBe('20000,50000,100000')->and($s->get('pricing.rounding'))->toBe(100)->and($s->get('hack.unknown'))->toBeNull();

    $this->actingAs($owner)->post('/admin/settings', ['values' => ['qr.window_minutes' => '0']])->assertSessionHasErrors('values');
    $this->actingAs($owner)->post('/admin/settings', ['values' => ['pos.quick_cash' => 'abc']])->assertSessionHasErrors('values');
    $this->actingAs(userWithRole('manager'))->post('/admin/settings', ['values' => ['qr.auto_confirm' => false]])->assertForbidden();
});

it('applies the anti-spam window and limit from settings', function () {
    setting('qr.max_submits_per_window', 2);
    qrOrder();
    qrOrder();
    expect(fn () => qrOrder())->toThrow(OrderException::class, 'Terlalu banyak');
});

it('uses the configured PIN attempt limit', function () {
    setting('security.pin_max_attempts', 2);
    $cashier = userWithRole('cashier');
    foreach ([1, 2] as $_) {
        try {
            app(ApprovalGate::class)->resolve($cashier->fresh(), 'void', '000000');
        } catch (OrderException) {
        }
    }
    expect(fn () => app(ApprovalGate::class)->resolve($cashier->fresh(), 'void', '222222'))->toThrow(OrderException::class, 'Terlalu banyak');
});

it('feeds quick cash buttons and ready-item window to the cashier screen', function () {
    setting('pos.quick_cash', '10000,20000');
    $this->actingAs(userWithRole('cashier'))->get('/pos')->assertInertia(fn ($p) => $p->where('settings.quick_cash', [10000, 20000]));
});

it('hides ready items from the kitchen after the configured minutes', function () {
    $order = placeOrder();
    $item = $order->items->first();
    $item->update(['status' => 'ready', 'ready_at' => now()->subMinutes(3)]);
    $url = '/kds/'.$item->station_id.'/tickets';
    $kitchen = userWithRole('kitchen');

    $this->actingAs($kitchen)->getJson($url)->assertJsonCount(1, 'tickets');
    setting('kitchen.ready_visible_minutes', 2);
    $this->actingAs($kitchen)->getJson($url)->assertJsonCount(0, 'tickets');
    expect(OrderItem::query()->count())->toBe(1);
});

it('customises the receipt from settings: header, toggles and digital link', function () {
    openShiftFor(userWithRole('cashier'), 0);
    Outlet::query()->update(['npwp' => '01.234.567.8-901.000']);
    $order = placeOrder();
    pay($order);
    $bill = $order->bills()->first();

    $r = ReceiptPresenter::forBill($bill);
    expect($r['cashier'])->not->toBeNull()->and($r['table'])->not->toBeNull()->and($r['outlet']['npwp'])->toBe('01.234.567.8-901.000')
        ->and($r['header'])->toBeNull()->and($r['show_link'])->toBeFalse();

    setting('receipt.header', 'WA 0812-0000-0000');
    setting('receipt.show_cashier', false);
    setting('receipt.show_table', false);
    setting('receipt.show_npwp', false);
    setting('receipt.show_link', true);
    setting('receipt.footer', 'Sampai jumpa lagi');

    $r = ReceiptPresenter::forBill($bill->fresh());
    expect($r['header'])->toBe('WA 0812-0000-0000')->and($r['cashier'])->toBeNull()->and($r['table'])->toBeNull()
        ->and($r['outlet']['npwp'])->toBeNull()->and($r['show_link'])->toBeTrue()->and($r['footer'])->toBe('Sampai jumpa lagi');
});
