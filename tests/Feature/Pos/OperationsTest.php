<?php

use App\Domain\Catalog\Models\Station;
use App\Domain\Floor\Models\Table;
use App\Domain\Ordering\Actions\ConfirmRoundAction;
use App\Domain\Ordering\Actions\SubmitQrOrderAction;
use App\Domain\Ordering\Actions\VoidItemAction;
use App\Domain\Ordering\Actions\VoidOrderAction;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\Models\OrderRound;
use App\Domain\Payment\Models\Payment;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Domain\Printing\PrintDispatcher;
use App\Domain\Reporting\KitchenReports;
use App\Domain\Settings\Models\Outlet;
use App\Domain\Shift\Models\Shift;
use App\Domain\Shift\ShiftCash;
use App\Support\BusinessDate;
use App\Support\RealtimeConfig;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

// ---------------------------------------------------------------- realtime config

it('hands the browser its WebSocket settings at runtime, derived from the page host', function () {
    config(['broadcasting.default' => 'reverb', 'reverb.apps.apps.0.key' => 'abc123']);

    $local = RealtimeConfig::forRequest(Request::create('http://localhost:8000/pos'));
    expect($local)->toBe(['key' => 'abc123', 'host' => 'localhost', 'port' => 8080, 'scheme' => 'http']);

    $this->app->detectEnvironment(fn () => 'production');
    $prod = RealtimeConfig::forRequest(Request::create('https://hmtc.example.com/pos', 'GET', [], [], [], ['HTTPS' => 'on']));
    expect($prod)->toBe(['key' => 'abc123', 'host' => 'hmtc.example.com', 'port' => 443, 'scheme' => 'https']);

    config(['pos.realtime.host' => 'ws.example.com', 'pos.realtime.port' => 6001, 'pos.realtime.scheme' => 'https']);
    expect(RealtimeConfig::forRequest(Request::create('http://x.test/'))['host'])->toBe('ws.example.com');
});

it('gives no realtime config when broadcasting is not set up (screen falls back to polling)', function () {
    config(['broadcasting.default' => 'null']);
    expect(RealtimeConfig::forRequest(Request::create('http://localhost/')))->toBeNull();

    config(['broadcasting.default' => 'reverb', 'reverb.apps.apps.0.key' => '']);
    expect(RealtimeConfig::forRequest(Request::create('http://localhost/')))->toBeNull();
});

it('shares the realtime config only with signed-in staff', function () {
    config(['broadcasting.default' => 'reverb', 'reverb.apps.apps.0.key' => 'abc123']);
    $token = Table::query()->first()->qr_token;
    $this->get("/m/{$token}")->assertInertia(fn ($p) => $p->where('realtime', null));

    $this->actingAs(userWithRole('cashier'))->get('/pos')->assertInertia(fn ($p) => $p->where('realtime.key', 'abc123')->where('realtime.host', 'localhost'));
});

// ---------------------------------------------------------------- new QR order alerts (polling fallback)

it('lists auto-accepted QR rounds in the polled state so the cashier is alerted even without WebSocket', function () {
    setting('qr.auto_confirm', true);
    $round = app(SubmitQrOrderAction::class)->handle(Table::query()->first()->qr_token, ['customer_name' => 'Rani', 'items' => [['product_id' => product('Americano')->id, 'qty' => 1]]]);

    $cashier = userWithRole('cashier');
    $this->actingAs($cashier)->getJson('/pos/state')->assertJsonPath('recent_qr.0.id', $round->id)->assertJsonPath('recent_qr.0.customer_name', 'Rani');

    $this->travel(4)->minutes();
    $this->actingAs($cashier)->getJson('/pos/state')->assertJsonCount(0, 'recent_qr');
});

it('lists pending QR rounds in the polled state and keeps manually confirmed ones out of recent_qr', function () {
    $round = app(SubmitQrOrderAction::class)->handle(Table::query()->first()->qr_token, ['customer_name' => 'Budi', 'items' => [['product_id' => product('Americano')->id, 'qty' => 1]]]);
    $this->actingAs(userWithRole('cashier'))->getJson('/pos/state')->assertJsonPath('pending_qr.0.id', $round->id)->assertJsonCount(0, 'recent_qr');

    app(ConfirmRoundAction::class)->handle($round, userWithRole('cashier'));
    $this->actingAs(userWithRole('cashier'))->getJson('/pos/state')->assertJsonCount(0, 'pending_qr')->assertJsonCount(0, 'recent_qr');
    expect(OrderRound::query()->count())->toBe(1);
});

// ---------------------------------------------------------------- shared shift

it('lets the owner take payments through the cashier\'s open shift', function () {
    $cashier = userWithRole('cashier');
    $shift = openShiftFor($cashier, 100000);
    $owner = userWithRole('owner');

    $this->actingAs($owner)->get('/pos')->assertInertia(fn ($p) => $p->where('shift.id', $shift->id)->where('shift.mine', false)->where('shift.owner', $cashier->name));

    $payment = pay(placeOrder($owner), $owner);
    expect($payment->shift_id)->toBe($shift->id)->and($payment->received_by)->toBe($owner->id);
    expect(app(ShiftCash::class)->summarize($shift->fresh())['cash_sales'])->toBe(44000);
});

it('still requires an own shift for cashiers, and when sharing is switched off', function () {
    openShiftFor(userWithRole('manager'), 0);   // manager's shift, cashier has none
    expect(fn () => pay(placeOrder(), userWithRole('cashier')))->toThrow(OrderException::class, 'shift');

    Shift::query()->delete();
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 0);
    setting('shift.shared_for_managers', false);
    expect(fn () => pay(placeOrder(), userWithRole('owner')))->toThrow(OrderException::class, 'shift');
    expect(Payment::query()->count())->toBe(0);
});

it('prefers the user\'s own shift over the shared one', function () {
    openShiftFor(userWithRole('cashier'), 0);
    $mine = openShiftFor(userWithRole('manager'), 0);
    expect(pay(placeOrder(), userWithRole('manager'))->shift_id)->toBe($mine->id);
});

it('shows other open shifts to the owner and lets them close one without being logged out', function () {
    $cashier = userWithRole('cashier');
    $shift = openShiftFor($cashier, 100000);
    pay(placeOrder());
    $owner = userWithRole('owner');

    $this->actingAs($owner)->get('/shift')->assertInertia(fn ($p) => $p->where('others.0.id', $shift->id)->where('others.0.user', $cashier->name)->where('sharing', true)->where('shift', null));

    $this->actingAs($owner)->post('/shift/close', ['shift_id' => $shift->id, 'counted_cash' => 144000, 'cash_left' => 100000, 'close_key' => 'k'])->assertRedirect(route('shift.index'));
    $this->assertAuthenticatedAs($owner);
    expect($shift->fresh()->closed_at)->not->toBeNull()->and($shift->fresh()->difference)->toBe(0);
});

it('does not let a cashier close someone else\'s shift by passing its id', function () {
    $manager = userWithRole('manager');
    $theirs = openShiftFor($manager, 0);
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 0);

    $this->actingAs($cashier)->post('/shift/close', ['shift_id' => $theirs->id, 'counted_cash' => 0, 'cash_left' => 0, 'close_key' => 'x']);
    expect($theirs->fresh()->closed_at)->toBeNull();
});

// ---------------------------------------------------------------- optional kitchen display

it('closes the kitchen display and hides its menus when KDS is off (default)', function () {
    setting('kitchen.enabled', false);
    $kitchen = userWithRole('kitchen');
    $stationId = Station::query()->value('id');

    $this->actingAs($kitchen)->get('/kds')->assertRedirect('/');
    $this->actingAs($kitchen)->getJson("/kds/{$stationId}/tickets")->assertNotFound();
    $this->actingAs(userWithRole('owner'))->get('/dashboard')->assertInertia(fn ($p) => $p->where('features.kds', false));

    $item = placeOrder()->items->first();
    $this->actingAs($kitchen)->postJson("/kds/items/{$item->id}/advance")->assertNotFound();

    $this->actingAs(userWithRole('cashier'))->get('/pos')->assertInertia(fn ($p) => $p->where('settings.kds_enabled', false)->where('state.ready_items', []));
});

it('is opened from Settings and then works again', function () {
    setting('kitchen.enabled', false);
    $this->actingAs(userWithRole('owner'))->post('/admin/settings', ['values' => ['kitchen.enabled' => true]])->assertSessionHasNoErrors();
    $this->actingAs(userWithRole('kitchen'))->get('/kds')->assertRedirect();
    $this->actingAs(userWithRole('owner'))->get('/dashboard')->assertInertia(fn ($p) => $p->where('features.kds', true));
});

// ---------------------------------------------------------------- kitchen performance report

function timedItem(string $product, int $waitMin, int $makeMin): void
{
    static $n = 0;
    $n %= 6;
    $order = placeOrder(items: [['product_id' => product($product)->id, 'qty' => 1]], tableId: Table::query()->orderBy('id')->skip($n++)->value('id'));
    $item = $order->items->first();
    $t0 = now()->subMinutes(60);
    $item->round->update(['confirmed_at' => $t0]);
    $item->update(['status' => 'ready', 'preparing_at' => $t0->copy()->addMinutes($waitMin), 'ready_at' => $t0->copy()->addMinutes($waitMin + $makeMin)]);
}

it('measures how many minutes the kitchen takes per item', function () {
    timedItem('Americano', 2, 4);    // 6 min
    timedItem('Americano', 1, 7);    // 8 min
    timedItem('Nasi Goreng', 5, 20); // 25 min -> slow (> 20)
    $d = BusinessDate::for();
    $r = app(KitchenReports::class);

    $s = $r->summary($d, $d, 20);
    expect($s['items'])->toBe(3)->and($s['avg_minutes'])->toBe(13.0)->and($s['slow'])->toBe(1)
        ->and($s['avg_wait'])->toBe(2.7)->and($s['avg_make'])->toBe(10.3);

    $byProduct = $r->by('product', $d, $d)->keyBy('label');
    expect($byProduct['Nasi Goreng']->avg_minutes)->toBe(25.0)->and($byProduct['Americano']->avg_minutes)->toBe(7.0)->and($byProduct['Americano']->items)->toBe(2);
    foreach (KitchenReports::DIMENSIONS as $dim) {
        expect($r->by($dim, $d, $d))->not->toBeEmpty();
    }
});

it('ignores voided and never-finished items in the kitchen report', function () {
    timedItem('Americano', 1, 4);
    $order = placeOrder(items: [['product_id' => product('Croissant')->id, 'qty' => 1]], tableId: Table::query()->skip(8)->value('id')); // never marked ready
    expect($order->items->first()->ready_at)->toBeNull();

    $d = BusinessDate::for();
    expect(app(KitchenReports::class)->summary($d, $d, 20)['items'])->toBe(1);
});

it('serves the kitchen report page and exports, only to those who may see sales reports', function () {
    timedItem('Americano', 1, 3);
    $d = BusinessDate::for();
    $manager = userWithRole('manager');

    $this->actingAs($manager)->get("/admin/reports/kitchen?from={$d}&to={$d}&dimension=hour")->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/reports/kitchen')->where('summary.items', 1)->where('enabled', true));
    $this->actingAs($manager)->get("/admin/reports/kitchen/export?from={$d}&to={$d}&dimension=station&format=xlsx")->assertOk();
    $this->actingAs($manager)->get("/admin/reports/kitchen/export?from={$d}&to={$d}&format=pdf")->assertOk();
    $this->actingAs(userWithRole('cashier'))->get('/admin/reports/kitchen')->assertForbidden();
    $this->actingAs($manager)->get('/admin/reports/kitchen?dimension=bogus')->assertStatus(302);
});

// ---------------------------------------------------------------- PIN login

it('logs floor staff in with their own PIN and sends them home', function () {
    $cashier = userWithRole('cashier');
    $cashier->forceFill(['pin_hash' => Hash::make('123456')])->save();

    $this->post('/login/pin', ['pin' => '123456'])->assertRedirect('/');
    $this->assertAuthenticatedAs($cashier);
});

it('rejects a wrong PIN, an inactive user and PINs of office roles', function () {
    $cashier = userWithRole('cashier');
    $cashier->forceFill(['pin_hash' => Hash::make('123456')])->save();
    $manager = userWithRole('manager');
    $manager->forceFill(['pin_hash' => Hash::make('222222')])->save();

    $this->post('/login/pin', ['pin' => '000000'])->assertSessionHasErrors('pin');
    $this->post('/login/pin', ['pin' => '222222'])->assertSessionHasErrors('pin'); // manager: email + password only
    $this->assertGuest();

    $cashier->update(['is_active' => false]);
    $this->post('/login/pin', ['pin' => '123456'])->assertSessionHasErrors('pin');
    $this->assertGuest();
});

it('lets the owner choose per role who may use PIN login, or turn it off', function () {
    $manager = userWithRole('manager');
    $manager->forceFill(['pin_hash' => Hash::make('222222')])->save();
    Role::findByName('manager')->forceFill(['allow_pin_login' => true])->save();
    $this->post('/login/pin', ['pin' => '222222'])->assertRedirect('/');
    $this->post('/logout');

    Role::query()->update(['allow_pin_login' => false]);
    $this->get('/login')->assertInertia(fn ($p) => $p->where('pinLogin', false));
    $this->post('/login/pin', ['pin' => '222222'])->assertSessionHasErrors('pin');
});

it('throttles PIN guessing', function () {
    foreach (range(1, 6) as $i) {
        $this->post('/login/pin', ['pin' => '00000'.$i]);
    }
    $this->post('/login/pin', ['pin' => '000007'])->assertStatus(429);
});

it('leaves owner 2FA off unless Pengaturan > Keamanan turns it on', function () {
    $this->actingAs(userWithRole('owner'))->get('/admin/menu')->assertOk();
});

// ---------------------------------------------------------------- cancelling without a manager

it('lets a cashier cancel an unpaid order or item without a PIN, with the reason and who did it reported', function () {
    $cashier = userWithRole('cashier');
    $order = placeOrder();
    $item = $order->items->first();

    app(VoidItemAction::class)->handle($item, $cashier, 'Salah input');
    expect($item->fresh()->voided_by)->toBe($cashier->id)->and($item->fresh()->void_reason)->toBe('Salah input');

    $other = placeOrder(items: [['product_id' => product('Americano')->id, 'qty' => 1]], tableId: Table::query()->skip(1)->value('id'));
    $this->actingAs($cashier)->postJson("/pos/orders/{$other->id}/void", ['reason' => 'Pelanggan batal'])->assertOk();
    expect($other->fresh()->status->value)->toBe('cancelled')->and($other->fresh()->cancel_reason)->toBe('Pelanggan batal');

    $d = BusinessDate::for();
    $this->actingAs(userWithRole('manager'))->get("/admin/reports/adjustments?from={$d}&to={$d}")
        ->assertInertia(fn ($p) => $p->where('voids.0.reason', fn ($r) => in_array($r, ['Salah input', 'Pelanggan batal'], true))->where('voids.0.actor', $cashier->name));
});

it('still demands a reason when cancelling, and refunds keep needing a manager', function () {
    $cashier = userWithRole('cashier');
    $order = placeOrder();
    expect(fn () => app(VoidItemAction::class)->handle($order->items->first(), $cashier, ' '))->toThrow(OrderException::class, 'Alasan');

    openShiftFor($cashier, 0);
    $paid = placeOrder(tableId: Table::query()->skip(2)->value('id'));
    pay($paid, $cashier);
    expect(fn () => app(VoidOrderAction::class)->handle($paid->fresh(), $cashier, 'x'))->toThrow(OrderException::class);
});

// ---------------------------------------------------------------- dine-in paid at the counter

it('takes a dine-in order at the counter with no table and gives it a call number', function () {
    $cashier = userWithRole('cashier');
    $res = $this->actingAs($cashier)->postJson('/pos/orders', ['type' => 'dine_in', 'counter' => true, 'items' => itemsPayload()])->assertCreated();

    expect($res->json('order.type'))->toBe('dine_in')->and($res->json('order.table'))->toBeNull()->and($res->json('order.call_number'))->toStartWith('A-');
    $this->actingAs($cashier)->postJson('/pos/orders', ['type' => 'dine_in', 'items' => itemsPayload()])->assertUnprocessable();
});

// ---------------------------------------------------------------- split bill

it('splits equally and per person, each bill payable on its own', function () {
    openShiftFor(userWithRole('cashier'), 0);
    $cashier = userWithRole('cashier');
    $order = placeOrder(); // 44.000

    $res = $this->actingAs($cashier)->postJson("/pos/orders/{$order->id}/split", ['mode' => 'equal', 'count' => 2])->assertOk();
    expect(collect($res->json('order.bills'))->where('status', 'unpaid')->pluck('grand_total')->sort()->values()->all())->toBe([22000, 22000]);

    $line = $order->items->first(); // 2 x Americano: one each
    $res = $this->actingAs($cashier)->postJson("/pos/orders/{$order->id}/split", ['mode' => 'item', 'parts' => [
        ['label' => 'Orang 1', 'items' => [['order_item_id' => $line->id, 'qty' => 1]]],
        ['label' => 'Orang 2', 'items' => [['order_item_id' => $line->id, 'qty' => 1]]],
    ]])->assertOk();
    expect(collect($res->json('order.bills'))->where('status', 'unpaid')->sum('grand_total'))->toBe(44000);
});

// ---------------------------------------------------------------- multiple printers

function printer(string $kind, ?int $station = null, int $copies = 1): Printer
{
    $outlet = (int) Outlet::query()->value('id');
    $p = Printer::create(['outlet_id' => $outlet, 'name' => ucfirst($kind), 'kind' => $kind, 'station_id' => $station, 'paper_width' => 58, 'copies' => $copies]);
    PrintDispatcher::forgetRouting($outlet);

    return $p;
}

it('queues nothing when no printer is configured, so the cashier device keeps printing', function () {
    openShiftFor(userWithRole('cashier'), 0);
    pay(placeOrder());
    expect(PrintJob::query()->count())->toBe(0);

    $this->actingAs(userWithRole('cashier'))->get('/pos')->assertInertia(fn ($p) => $p->where('print_routing.receipt', false)->where('print_routing.ticket', false));
});

it('routes the receipt, the bill and each station ticket to its own printer', function () {
    $bar = Station::query()->where('name', 'Bar')->value('id') ?? Station::query()->first()->id;
    $kitchen = Station::query()->where('id', '!=', $bar)->value('id') ?? $bar;
    $receipt = printer('receipt');
    $bill = printer('bill');
    $barPrinter = printer('ticket', $bar);
    $kitchenPrinter = printer('ticket', $kitchen);

    openShiftFor(userWithRole('cashier'), 0);
    $order = placeOrder(items: [['product_id' => product('Americano')->id, 'qty' => 2], ['product_id' => product('Nasi Goreng')->id, 'qty' => 1]]);
    $jobs = PrintJob::query();

    $tickets = (clone $jobs)->where('kind', 'ticket')->get();
    expect($tickets->pluck('printer_id')->unique()->sort()->values()->all())->toBe(collect([$barPrinter->id, $kitchenPrinter->id])->unique()->sort()->values()->all());
    expect($tickets->first()->payload['items'][0])->toHaveKeys(['qty', 'name', 'note', 'modifiers']);

    $this->actingAs(userWithRole('cashier'))->postJson("/pos/orders/{$order->id}/bill-request")->assertOk();
    expect((clone $jobs)->where('kind', 'bill')->where('printer_id', $bill->id)->count())->toBe(1)
        ->and((clone $jobs)->where('kind', 'bill')->first()->payload['title'])->toBe('TAGIHAN');

    pay($order);
    expect((clone $jobs)->where('kind', 'receipt')->where('printer_id', $receipt->id)->count())->toBe(1);
});

it('lets a print station fetch, finish, fail and retry its jobs', function () {
    $receipt = printer('receipt', copies: 2);
    openShiftFor(userWithRole('cashier'), 0);
    pay(placeOrder());
    $cashier = userWithRole('cashier');

    $res = $this->actingAs($cashier)->getJson("/print-station/jobs?printers={$receipt->id}")->assertOk();
    expect($res->json('pending'))->toHaveCount(1)->and($res->json('pending.0.payload.copies'))->toBe(2);
    $id = $res->json('pending.0.id');

    $this->actingAs($cashier)->postJson("/print-station/jobs/{$id}/done", ['ok' => false, 'error' => 'Printer mati'])->assertOk();
    $this->actingAs($cashier)->getJson("/print-station/jobs?printers={$receipt->id}")->assertJsonCount(0, 'pending')->assertJsonPath('recent.0.status', 'failed');

    $this->actingAs($cashier)->postJson("/print-station/jobs/{$id}/retry")->assertOk();
    $this->actingAs($cashier)->postJson("/print-station/jobs/{$id}/done", ['ok' => true])->assertOk();
    expect(PrintJob::query()->find($id)->status)->toBe('printed');
});

it('reprints a receipt to the printer as a COPY and manages printers from the admin page', function () {
    $receipt = printer('receipt');
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 0);
    $order = placeOrder();
    pay($order);
    PrintJob::query()->delete();

    $bill = $order->bills()->first();
    $this->actingAs($cashier)->postJson("/pos/bills/{$bill->id}/print")->assertOk()->assertJsonPath('queued', 1);
    expect(PrintJob::query()->first()->payload['copy'])->toBeTrue();

    $owner = userWithRole('owner');
    $this->actingAs($owner)->post('/admin/printers', ['name' => 'Bar', 'kind' => 'ticket', 'station_id' => Station::query()->value('id'), 'paper_width' => 58, 'copies' => 1])->assertSessionHasNoErrors();
    $this->actingAs($owner)->get('/admin/printers')->assertInertia(fn ($p) => $p->has('printers', 2));
    $this->actingAs($cashier)->get('/admin/printers')->assertForbidden();
    $this->actingAs($owner)->delete('/admin/printers/'.$receipt->id)->assertSessionHasNoErrors();
});
