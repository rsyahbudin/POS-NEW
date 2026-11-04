<?php

use App\Domain\Floor\Models\Table;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\Models\Order;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\ReservationService;
use Carbon\CarbonImmutable;

function openReservations(): string
{
    $tomorrow = CarbonImmutable::now('Asia/Jakarta')->addDay()->toDateString();
    setting('reservation.enabled', true);
    setting('reservation.start_date', $tomorrow);
    setting('reservation.end_date', CarbonImmutable::now('Asia/Jakarta')->addDays(20)->toDateString());
    setting('reservation.slots', '18:00,19:30');
    setting('reservation.duration_minutes', 120);
    product('Americano')->update(['reservation_menu' => true]);

    return $tomorrow;
}

/** @return array<string, mixed> */
function booking(string $date, int $pax = 4, string $slot = '18:00'): array
{
    return ['name' => 'Rani', 'phone' => '0812 3456 7890', 'pax' => $pax, 'date' => $date, 'slot' => $slot, 'items' => [['product_id' => product('Americano')->id, 'qty' => 2]]];
}

it('stays closed until switched on and only takes days inside the Ramadan period', function () {
    $date = CarbonImmutable::now('Asia/Jakarta')->addDay()->toDateString();
    $this->get('/reservasi')->assertOk()->assertInertia(fn ($p) => $p->component('reservation/book')->where('period', null)->where('menu', []));
    expect(fn () => app(ReservationService::class)->create(booking($date)))->toThrow(OrderException::class, 'belum dibuka');

    openReservations();
    $service = app(ReservationService::class);
    expect(fn () => $service->create(booking(CarbonImmutable::now('Asia/Jakarta')->addDays(40)->toDateString())))->toThrow(OrderException::class, 'belum bisa dipesan');
    expect(fn () => $service->create(booking($date, 4, '20:00')))->toThrow(OrderException::class, 'Pilih jam');
});

it('lets guests order only from the reservation menu', function () {
    $date = openReservations();
    $service = app(ReservationService::class);
    $this->get('/reservasi')->assertInertia(fn ($p) => $p->has('menu', 1)->where('menu.0.products.0.name', 'Americano')->has('menu.0.products', 1));

    $other = booking($date);
    $other['items'] = [['product_id' => product('Croissant')->id, 'qty' => 1]];
    expect(fn () => $service->create($other))->toThrow(OrderException::class, 'menu reservasi');
});

it('holds one table for a small party, joins neighbouring tables for a big one, and says when a slot is full', function () {
    $date = openReservations();
    $service = app(ReservationService::class);

    $small = $service->create(booking($date, 3));
    expect($small->tables)->toHaveCount(1)->and($small->items_total)->toBe(44000)->and($small->status)->toBe('booked');

    $big = $service->create(booking($date, 10));          // tables seat 4 each: three tables of one area
    expect($big->tables->count())->toBe(3)->and($big->tables->sum('capacity'))->toBeGreaterThanOrEqual(10)->and($big->tables->pluck('id')->intersect($small->tables->pluck('id')))->toBeEmpty();

    // 19:30 overlaps the 120-minute hold of 18:00, so a party of 10 no longer fits, but one table is still free for four
    expect($service->availability($date, 10)['19:30'])->toBeFalse()->and($service->availability($date, 4)['19:30'])->toBeTrue();
    expect(fn () => $service->create(booking($date, 200)))->toThrow(OrderException::class);
});

it('seats a party: one order on the first table, the other tables joined, one bill', function () {
    $date = openReservations();
    $service = app(ReservationService::class);
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 0);

    $r = $service->create(booking($date, 10));
    $tables = $r->tables;
    $order = $service->seat($r, $cashier);

    expect($r->fresh()->status)->toBe('seated')->and($order->table_id)->toBe($tables->first()->id)->and($order->customer_name)->toBe('Rani')
        ->and($order->joinedTables->pluck('id')->sort()->values()->all())->toBe($tables->skip(1)->pluck('id')->sort()->values()->all())
        ->and($order->items()->count())->toBe(1);
    foreach ($tables as $t) {
        expect($t->fresh()->status->value)->toBe('occupied');
    }

    // the QR of a joined table adds to the same order, never to a new one
    $joined = $tables->last();
    $this->postJson("/m/{$joined->qr_token}/order", ['customer_name' => 'Budi', 'items' => [['product_id' => product('Croissant')->id, 'qty' => 1]]])->assertCreated();
    expect(Order::query()->whereIn('status', ['open', 'partially_paid'])->count())->toBe(1)->and($order->fresh()->rounds()->count())->toBe(2);

    // the cashier confirms it like any QR round, then paying the one bill frees every table
    $pending = $order->rounds()->where('status', 'pending')->firstOrFail();
    $this->actingAs($cashier)->postJson("/pos/rounds/{$pending->id}/confirm")->assertOk();
    $order->refresh();
    pay($order, $cashier);
    foreach ($tables as $t) {
        expect($t->fresh()->status->value)->toBe('empty');
    }
});

it('cannot seat on a table that still has a guest, and can move a booking to other free tables', function () {
    $date = openReservations();
    $service = app(ReservationService::class);
    $cashier = userWithRole('cashier');
    $r = $service->create(booking($date, 3));
    $table = $r->tables->first();

    openShiftFor($cashier, 0);
    placeOrder($cashier, [['product_id' => product('Croissant')->id, 'qty' => 1]], 'dine_in', $table->id);
    expect(fn () => $service->seat($r, $cashier))->toThrow(OrderException::class, 'masih terisi');

    $free = Table::query()->where('id', '!=', $table->id)->where('capacity', '>=', 3)->first();
    $service->retable($r, [$free->id], $cashier);
    expect($r->fresh()->tables->pluck('id')->all())->toBe([$free->id]);
    expect(fn () => $service->retable($r, [Table::query()->where('capacity', 2)->firstOrFail()->id], $cashier))->toThrow(OrderException::class, 'belum cukup');
});

it('takes a booking over HTTP, shows it to the guest, and lets staff with the permission handle it', function () {
    $date = openReservations();

    $res = $this->postJson('/reservasi', booking($date, 4))->assertCreated();
    $code = $res->json('code');
    $this->get("/reservasi/{$code}")->assertOk()->assertInertia(fn ($p) => $p->component('reservation/status')->where('reservation.name', 'Rani')->where('reservation.total', 44000));
    $this->getJson("/reservasi/slots?date={$date}&pax=4")->assertOk()->assertJsonStructure(['slots' => ['18:00', '19:30']]);
    $this->postJson('/reservasi', ['name' => 'x'])->assertStatus(422);

    $r = Reservation::query()->where('public_id', $code)->firstOrFail();
    $this->actingAs(userWithRole('kitchen'))->get('/reservations')->assertForbidden();
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 0);
    $this->actingAs($cashier)->get("/reservations?date={$date}")->assertOk()->assertInertia(fn ($p) => $p->component('reservations/index')->has('reservations', 1));
    $this->actingAs($cashier)->post("/reservations/{$r->id}/seat")->assertSessionHas('success');
    expect($r->fresh()->status)->toBe('seated');
    $this->actingAs($cashier)->post("/reservations/{$r->id}/close", ['status' => 'cancelled'])->assertSessionHas('error');

    $second = app(ReservationService::class)->create(booking($date, 2, '19:30'));
    $this->actingAs($cashier)->post("/reservations/{$second->id}/close", ['status' => 'no_show'])->assertSessionHas('success');
    expect($second->fresh()->status)->toBe('no_show');
});

it('marks booked tables on the floor shortly before the booking and shows joined tables as one order', function () {
    $date = openReservations();
    $service = app(ReservationService::class);
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 0);
    $r = $service->create(booking($date, 8));
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
