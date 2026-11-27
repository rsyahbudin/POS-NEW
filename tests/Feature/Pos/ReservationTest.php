<?php

use App\Domain\Floor\Models\Table;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\Models\Order;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Opens reservations for tomorrow onwards; Americano is the package (one per person), Croissant a normal menu item. */
function openReservations(): string
{
    $tomorrow = CarbonImmutable::now('Asia/Jakarta')->addDay()->toDateString();
    setting('reservation.enabled', true);
    setting('reservation.start_date', $tomorrow);
    setting('reservation.end_date', CarbonImmutable::now('Asia/Jakarta')->addDays(20)->toDateString());
    setting('reservation.time', '18:00');
    setting('reservation.capacity', 200);
    product('Americano')->update(['reservation_menu' => true, 'reservation_package' => true]);

    return $tomorrow;
}

/** @return array<string, mixed> one package per person unless told otherwise */
function booking(string $date, int $pax = 4, ?int $packages = null, string $type = 'dp'): array
{
    return ['name' => 'Rani', 'phone' => '0812 3456 7890', 'pax' => $pax, 'date' => $date, 'payment_type' => $type, 'items' => [['product_id' => product('Americano')->id, 'qty' => $packages ?? $pax]]];
}

it('stays closed until switched on and only takes days inside the Ramadan period', function () {
    $date = CarbonImmutable::now('Asia/Jakarta')->addDay()->toDateString();
    $this->get('/reservasi')->assertOk()->assertInertia(fn ($p) => $p->component('reservation/book')->where('period', null)->where('menu', []));
    expect(fn () => app(ReservationService::class)->create(booking($date)))->toThrow(OrderException::class, 'belum dibuka');

    openReservations();
    $service = app(ReservationService::class);
    expect(fn () => $service->create(booking(CarbonImmutable::now('Asia/Jakarta')->addDays(40)->toDateString())))->toThrow(OrderException::class, 'belum bisa dipesan');
    expect(fn () => $service->create(booking($date, 40)))->toThrow(OrderException::class, 'Jumlah orang');
});

it('lets guests order only from the reservation menu', function () {
    $date = openReservations();
    $this->get('/reservasi')->assertInertia(fn ($p) => $p->has('menu', 1)->where('menu.0.products.0.name', 'Americano')->has('menu.0.products', 1)->where('packageIds', [product('Americano')->id]));

    $other = booking($date);
    $other['items'] = [['product_id' => product('Croissant')->id, 'qty' => 1]];
    expect(fn () => app(ReservationService::class)->create($other))->toThrow(OrderException::class, 'menu reservasi');
});

it('counts people against the daily quota, and frees them when a booking is cancelled', function () {
    $date = openReservations();
    setting('reservation.capacity', 10);
    $service = app(ReservationService::class);

    $first = $service->create(booking($date, 6));
    expect($first->status)->toBe('pending')->and($service->availability($date))->toMatchArray(['open' => true, 'capacity' => 10, 'remaining' => 4]);
    expect(fn () => $service->create(booking($date, 6)))->toThrow(OrderException::class, 'Sisa kuota hari itu 4');

    $service->create(booking($date, 4));
    expect($service->availability($date))->toMatchArray(['open' => false, 'remaining' => 0])->and($service->availability($date)['reason'])->toContain('penuh');

    $service->close($first, 'cancelled', userWithRole('manager'));
    expect($service->availability($date)['remaining'])->toBe(6);
    $this->getJson("/reservasi/slots?date={$date}")->assertOk()->assertJsonPath('remaining', 6);
});

it('closes new bookings after the cut-off time on the day itself', function () {
    openReservations();
    $today = CarbonImmutable::now('Asia/Jakarta')->toDateString();
    setting('reservation.start_date', $today);
    $service = app(ReservationService::class);

    setting('reservation.cutoff_time', '00:00');
    expect($service->availability($today)['open'])->toBeFalse()->and($service->availability($today)['reason'])->toContain('ditutup pukul 00:00');
    expect($service->availability(CarbonImmutable::now('Asia/Jakarta')->addDay()->toDateString())['open'])->toBeTrue();

    setting('reservation.cutoff_time', '23:59');
    expect($service->availability($today)['open'])->toBeTrue();
});

it('asks for a deposit or the full amount, and flags fewer packages than people', function () {
    $date = openReservations();
    $service = app(ReservationService::class);

    $dp = $service->create(booking($date, 4));          // 4 x 22.000 = 88.000, 30% = 26.400 -> rounded up to the thousand
    expect($dp->payment_type)->toBe('dp')->and($dp->items_total)->toBe(88000)->and($dp->amount_due)->toBe(27000)->and($dp->package_short)->toBeFalse();

    $full = $service->create(booking($date, 4, 6, 'full'));   // more packages than people is fine
    expect($full->amount_due)->toBe(132000)->and($full->package_short)->toBeFalse();

    $short = $service->create(booking($date, 5, 3));
    expect($short->package_short)->toBeTrue();

    setting('reservation.package_required', false);
    expect($service->create(booking($date, 5, 3))->package_short)->toBeFalse();
});

it('waits for staff to confirm the payment before seating, and a short package needs their say-so', function () {
    $date = openReservations();
    $service = app(ReservationService::class);
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 0);

    $r = $service->create(booking($date, 4, 3));
    $service->autoAssign($date, $cashier);
    expect(fn () => $service->seat($r->fresh(), $cashier))->toThrow(OrderException::class, 'Konfirmasi pembayarannya');
    expect(fn () => $service->confirm($r, 27000, false, $cashier))->toThrow(OrderException::class, 'Paket kurang');

    $service->confirm($r, 20000, true, $cashier);
    $r->refresh();
    expect($r->status)->toBe('booked')->and($r->paid_amount)->toBe(20000)->and($r->package_waived)->toBeTrue()->and($r->confirmed_by)->toBe($cashier->id);

    $order = $service->seat($r, $cashier);
    expect($r->fresh()->status)->toBe('seated')->and($order->customer_name)->toBe('Rani');
});

it('gives tables to waiting bookings in order and joins neighbouring tables for a big party', function () {
    $date = openReservations();
    $service = app(ReservationService::class);
    $cashier = userWithRole('cashier');

    $small = $service->create(booking($date, 3));
    $big = $service->create(booking($date, 10));          // tables seat 4 each
    expect($small->tables)->toHaveCount(0);
    expect($service->autoAssign($date, $cashier))->toBe(2);

    $small->load('tables');
    $big->load('tables');
    expect($small->tables)->toHaveCount(1)->and($big->tables->count())->toBe(3)->and($big->tables->sum('capacity'))->toBeGreaterThanOrEqual(10)
        ->and($big->tables->pluck('id')->intersect($small->tables->pluck('id')))->toBeEmpty();
    expect($service->autoAssign($date, $cashier))->toBe(0);
});

it('seats a party: one order on the first table, the other tables joined, one bill', function () {
    $date = openReservations();
    $service = app(ReservationService::class);
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 0);

    $r = $service->create(booking($date, 10));
    $service->autoAssign($date, $cashier);
    $service->confirm($r, 80000, false, $cashier);
    $r->refresh();
    $tables = $r->tables;
    $order = $service->seat($r, $cashier);

    expect($r->fresh()->status)->toBe('seated')->and($order->table_id)->toBe($tables->first()->id)->and($order->customer_name)->toBe('Rani')
        ->and($order->joinedTables->pluck('id')->sort()->values()->all())->toBe($tables->skip(1)->pluck('id')->sort()->values()->all())
        ->and($order->items()->count())->toBe(1);
    foreach ($tables as $t) {
        expect($t->fresh()->status->value)->toBe('occupied');
    }

    // before the normal menu opens only the reservation menu can be added from the table QR
    $joined = $tables->last();
    $croissant = ['customer_name' => 'Budi', 'items' => [['product_id' => product('Croissant')->id, 'qty' => 1]]];
    $this->postJson("/m/{$joined->qr_token}/order", $croissant)->assertStatus(422);
    $this->postJson("/m/{$joined->qr_token}/order", ['customer_name' => 'Budi', 'items' => [['product_id' => product('Americano')->id, 'qty' => 1]]])->assertCreated();

    // staff can open the normal menu for this party early, and then it joins the same bill
    $this->actingAs(userWithRole('cs'))->post("/reservations/{$r->id}/full-menu", ['on' => true])->assertSessionHas('success');
    $this->postJson("/m/{$joined->qr_token}/order", $croissant)->assertCreated();
    expect(Order::query()->whereIn('status', ['open', 'partially_paid'])->count())->toBe(1)->and($order->fresh()->rounds()->count())->toBe(3);

    foreach ($order->rounds()->where('status', 'pending')->get() as $pending) {
        $this->actingAs($cashier)->postJson("/pos/rounds/{$pending->id}/confirm")->assertOk();
    }
    $order->refresh();
    pay($order, $cashier);
    foreach ($tables as $t) {
        expect($t->fresh()->status->value)->toBe('empty');
    }
});

it('opens the normal menu by itself once the set time has passed', function () {
    $date = openReservations();
    $service = app(ReservationService::class);
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 0);
    $r = $service->create(booking($date, 2));
    $service->autoAssign($date, $cashier);
    $service->confirm($r, 20000, false, $cashier);
    $r->refresh();
    $service->seat($r, $cashier);
    $order = $r->fresh()->order;
    $croissant = [['product_id' => product('Croissant')->id, 'qty' => 1]];

    expect(fn () => $service->guardQr($order, [['product_id' => product('Croissant')->id]]))->toThrow(OrderException::class, '19:00');
    // the day of the booking has begun and 19:00 has passed
    $r->update(['starts_at' => now()->subDay()]);
    $service->guardQr($order, [['product_id' => product('Croissant')->id]]);
    expect($croissant)->toHaveCount(1);
});

it('lets the guest change and cancel until the deadline, and tells staff', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    $date = openReservations();
    $service = app(ReservationService::class);
    $r = $service->create(booking($date, 4));
    $line = $r->items[0];

    $res = $this->postJson("/reservasi/{$r->public_id}/ubah", ['pax' => 6, 'phone' => '081234567890', 'note' => 'Dekat jendela', 'keep' => [['index' => 0, 'qty' => 6]], 'add' => []])->assertOk();
    $r->refresh();
    expect($r->pax)->toBe(6)->and($r->items_total)->toBe(132000)->and($r->amount_due)->toBe(40000)->and($r->note)->toBe('Dekat jendela')->and($r->changed_at)->not->toBeNull()->and($r->package_short)->toBeFalse();

    // fewer packages than people is flagged again; a line with no quantity is dropped, new lines are added
    $this->postJson("/reservasi/{$r->public_id}/ubah", ['pax' => 6, 'phone' => '081234567890', 'keep' => [['index' => 0, 'qty' => 2]], 'add' => []])->assertOk();
    expect($r->fresh()->package_short)->toBeTrue()->and($r->fresh()->items[0]['product_id'])->toBe($line['product_id']);
    $this->postJson("/reservasi/{$r->public_id}/ubah", ['pax' => 6, 'phone' => '081234567890', 'keep' => [['index' => 0, 'qty' => 0]], 'add' => [['product_id' => product('Croissant')->id, 'qty' => 1]]])->assertStatus(422);
    $this->postJson("/reservasi/{$r->public_id}/ubah", ['pax' => 6, 'phone' => '081234567890', 'keep' => [['index' => 0, 'qty' => 0]], 'add' => []])->assertStatus(422);

    $this->get("/reservasi/{$r->public_id}/ubah")->assertOk()->assertInertia(fn ($p) => $p->component('reservation/book')->where('editing.pax', 6)->has('editing.lines', 1));
    $this->postJson("/reservasi/{$r->public_id}/batal")->assertOk();
    expect($r->fresh()->status)->toBe('cancelled');
    $this->postJson("/reservasi/{$r->public_id}/batal")->assertStatus(422);
});

it('lets the party order the normal menu once staff opened it, and refuses guest changes after the deadline', function () {
    $date = openReservations();
    $service = app(ReservationService::class);
    $manager = userWithRole('manager');
    $r = $service->create(booking($date, 2));

    $add = [['product_id' => product('Croissant')->id, 'qty' => 1]];
    expect(fn () => $service->update($r, ['pax' => 2, 'keep' => [['index' => 0, 'qty' => 2]], 'add' => $add]))->toThrow(OrderException::class, 'menu reservasi');
    $service->setFullMenu($r, true, $manager);
    $service->update($r->refresh(), ['pax' => 2, 'keep' => [['index' => 0, 'qty' => 2]], 'add' => $add]);
    expect($r->fresh()->items)->toHaveCount(2);

    // the day of the booking arrives and the change time passed: only staff can still change it
    $r->update(['starts_at' => now()->subDay()]);
    expect($service->guestCanChange($r->fresh()))->toBeFalse();
    expect(fn () => $service->cancelByGuest($r->fresh()))->toThrow(OrderException::class, 'lewat pengelola');
    $service->update($r->fresh(), ['pax' => 3, 'keep' => [['index' => 0, 'qty' => 3]], 'add' => []], $manager);
    expect($r->fresh()->pax)->toBe(3);
});

it('takes a booking over HTTP, shows it to the guest, and lets staff with the permission handle it', function () {
    $date = openReservations();

    $res = $this->postJson('/reservasi', booking($date, 4))->assertCreated();
    $code = $res->json('code');
    $this->get("/reservasi/{$code}")->assertOk()->assertInertia(fn ($p) => $p->component('reservation/status')->where('reservation.name', 'Rani')->where('reservation.total', 88000)->where('reservation.status', 'pending')->where('reservation.can_change', true));
    $this->postJson('/reservasi', ['name' => 'x'])->assertStatus(422);

    $r = Reservation::query()->where('public_id', $code)->firstOrFail();
    $this->actingAs(userWithRole('kitchen'))->get('/reservations')->assertForbidden();
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 0);
    $this->actingAs(userWithRole('cs'))->get("/reservations?date={$date}")->assertOk()->assertInertia(fn ($p) => $p->component('reservations/index')->has('reservations', 1)->where('day.booked', 4)->where('day.remaining', 196));

    $this->actingAs(userWithRole('cs'))->post("/reservations/{$r->id}/seat")->assertSessionHas('error');                                     // not confirmed yet
    $this->actingAs(userWithRole('cs'))->post('/reservations/assign', ['date' => $date])->assertSessionHas('success');
    $this->actingAs(userWithRole('cs'))->post("/reservations/{$r->id}/confirm", ['amount' => 30000])->assertSessionHas('success');
    $this->actingAs(userWithRole('cs'))->post("/reservations/{$r->id}/seat")->assertSessionHas('success');
    expect($r->fresh()->status)->toBe('seated')->and($r->fresh()->paid_amount)->toBe(30000);
    $this->actingAs(userWithRole('cs'))->post("/reservations/{$r->id}/close", ['status' => 'cancelled'])->assertSessionHas('error');

    $second = app(ReservationService::class)->create(booking($date, 2));
    $this->actingAs(userWithRole('cs'))->post("/reservations/{$second->id}/close", ['status' => 'no_show'])->assertSessionHas('success');
    expect($second->fresh()->status)->toBe('no_show');
});

it('marks booked tables on the floor shortly before the booking and shows joined tables as one order', function () {
    $date = openReservations();
    $service = app(ReservationService::class);
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 0);
    $r = $service->create(booking($date, 8));
    $service->autoAssign($date, $cashier);
    $service->confirm($r, 50000, false, $cashier);
    $r->refresh();
    $floor = fn () => collect($this->actingAs($cashier)->get('/pos')->viewData('page')['props']['state']['floor'])->flatMap(fn ($a) => $a['tables'])->keyBy('id');

    // tomorrow evening is far away: nothing is marked yet
    expect($floor()->get($r->tables->first()->id)['reserved'])->toBeNull();
    setting('reservation.floor_ahead_minutes', 60 * 48);
    expect($floor()->get($r->tables->first()->id)['reserved'])->toMatchArray(['name' => 'Rani', 'pax' => 8, 'time' => '18:00']);

    $order = $service->seat($r, $cashier);
    $tables = $floor();
    expect($tables->get($r->tables->first()->id)['order']['joined_to'])->toBeNull()->and($tables->get($r->tables->last()->id)['order']['id'])->toBe($order->id)
        ->and($tables->get($r->tables->last()->id)['order']['joined_to'])->toBe($r->tables->first()->name);
});

it('moves a booking to other free tables only when they seat everybody', function () {
    $date = openReservations();
    $service = app(ReservationService::class);
    $cashier = userWithRole('cashier');
    $r = $service->create(booking($date, 3));
    $service->autoAssign($date, $cashier);
    $table = $r->fresh()->tables->first();

    $free = Table::query()->where('id', '!=', $table->id)->where('capacity', '>=', 3)->first();
    $service->retable($r, [$free->id], $cashier);
    expect($r->fresh()->tables->pluck('id')->all())->toBe([$free->id]);
    expect(fn () => $service->retable($r, [Table::query()->where('capacity', 2)->firstOrFail()->id], $cashier))->toThrow(OrderException::class, 'belum cukup');
});

it('keeps reservations to owner, manager and customer service, and seating to those allowed to seat', function () {
    $date = openReservations();
    $r = app(ReservationService::class)->create(booking($date, 2));

    foreach (['cashier', 'waiter', 'kitchen'] as $role) {
        $this->actingAs(userWithRole($role))->get('/reservations')->assertForbidden();
        $this->actingAs(userWithRole($role))->post("/reservations/{$r->id}/confirm", ['amount' => 1000])->assertForbidden();
    }
    foreach (['owner', 'manager', 'cs'] as $role) {
        $this->actingAs(userWithRole($role))->get('/reservations')->assertOk();
    }

    // someone who may manage but not seat cannot press Duduk
    $viewer = userWithRole('cs');
    Role::findByName('cs')->revokePermissionTo('seat-reservations');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($viewer)->post("/reservations/{$r->id}/seat")->assertForbidden();
    $this->actingAs($viewer)->post("/reservations/{$r->id}/confirm", ['amount' => 1000])->assertSessionHasNoErrors();
});

it('takes the deposit off the bill when the party is seated', function () {
    $date = openReservations();
    $service = app(ReservationService::class);
    $cs = userWithRole('cs');
    openShiftFor(userWithRole('cashier'), 0);

    $r = $service->create(booking($date, 4));            // 4 x 22.000, deposit 30% = 27.000
    $service->autoAssign($date, $cs);
    $this->actingAs($cs)->post("/reservations/{$r->id}/confirm", ['amount' => 27000])->assertSessionHas('success');
    $this->actingAs($cs)->post("/reservations/{$r->id}/seat")->assertSessionHas('success');

    $r->refresh();
    $bill = $r->order->bills()->firstOrFail();
    expect($r->deposit_payment_id)->not->toBeNull()->and($bill->paid_total)->toBe(27000)->and($bill->remaining())->toBe($bill->grand_total - 27000)
        ->and($bill->payments()->firstOrFail()->reference)->toBe("DP reservasi {$r->public_id}");
    $this->actingAs($cs)->post("/reservations/{$r->id}/deposit")->assertSessionHas('error');   // not twice
});

it('seats the party even when the deposit cannot be taken off yet, and lets staff do it afterwards', function () {
    $date = openReservations();
    $service = app(ReservationService::class);
    $cs = userWithRole('cs');
    $r = $service->create(booking($date, 2));
    $service->autoAssign($date, $cs);
    $service->confirm($r, 15000, false, $cs);

    $this->actingAs($cs)->post("/reservations/{$r->id}/seat")->assertSessionHas('success', fn ($m) => str_contains($m, 'DP belum dipotong'));
    expect($r->fresh()->status)->toBe('seated')->and($r->fresh()->deposit_payment_id)->toBeNull();

    openShiftFor(userWithRole('cashier'), 0);
    $this->actingAs($cs)->post("/reservations/{$r->id}/deposit")->assertSessionHas('success');
    expect($r->fresh()->deposit_payment_id)->not->toBeNull();
});

it('lets customer service book for a caller past the closing time and the quota, and fix the booking later', function () {
    $date = openReservations();
    setting('reservation.capacity', 4);
    $cs = userWithRole('cs');
    $this->actingAs($cs)->get('/reservations/baru')->assertOk()->assertInertia(fn ($p) => $p->component('reservation/book')->where('staff', true));

    $body = booking($date, 10, 10) + ['paid' => 100000];
    $this->postJson('/reservasi', booking($date, 10))->assertStatus(422);                       // the public form is held back by the quota
    $this->actingAs($cs)->postJson('/reservations', $body)->assertCreated()->assertJsonPath('date', $date);
    $r = Reservation::query()->firstOrFail();
    expect($r->status)->toBe('booked')->and($r->paid_amount)->toBe(100000)->and($r->pax)->toBe(10);

    $this->actingAs($cs)->postJson("/reservations/{$r->id}/ubah", ['name' => 'Rani Putri', 'pax' => 12, 'phone' => '081234567890', 'keep' => [['index' => 0, 'qty' => 12]], 'add' => []])->assertOk();
    expect($r->fresh()->name)->toBe('Rani Putri')->and($r->fresh()->pax)->toBe(12);
    $this->actingAs($cs)->get("/reservations/{$r->id}/ubah")->assertOk()->assertInertia(fn ($p) => $p->where('editing.name', 'Rani Putri')->where('staff', true));
    $this->actingAs(userWithRole('cashier'))->postJson('/reservations', $body)->assertForbidden();
});
