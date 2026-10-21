<?php

use App\Domain\Floor\Models\Table;
use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Inventory\Models\RecipeLine;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Notification\Models\AppNotification;
use App\Domain\Notification\Notifier;
use App\Domain\Ordering\Actions\RequestBillAction;
use App\Domain\Ordering\Actions\UpdateItemStatusAction;
use App\Domain\Payment\Models\Bill;
use App\Domain\Settings\Models\Outlet;
use App\Domain\Shift\Models\Shift;
use App\Models\User;
use App\Support\BusinessDate;
use Spatie\Activitylog\Models\Activity;

// ---------------------------------------------------------------- notifications stay light

it('prunes old notifications and keeps the bell to the recent window', function () {
    $outlet = (int) Outlet::query()->value('id');
    $n = app(Notifier::class);
    $fresh = $n->notify($outlet, 'x', 'Baru');
    $oldInfo = $n->notify($outlet, 'x', 'Info 8 hari', null, null, 'info');
    $oldWarn = $n->notify($outlet, 'x', 'Peringatan 8 hari', null, null, 'warning');
    $ancient = $n->notify($outlet, 'x', 'Sangat lama', null, null, 'danger');
    $resolved = $n->notify($outlet, 'x', 'Selesai', null, null, 'warning', null, null, 'k1');
    AppNotification::query()->whereKey($oldInfo->id)->update(['created_at' => now()->subDays(8)]);
    AppNotification::query()->whereKey($oldWarn->id)->update(['created_at' => now()->subDays(8)]);
    AppNotification::query()->whereKey($ancient->id)->update(['created_at' => now()->subDays(31)]);
    AppNotification::query()->whereKey($resolved->id)->update(['resolved_at' => now()->subDays(4)]);

    $titles = fn () => collect($this->actingAs(userWithRole('manager'))->getJson('/notifications')->json('items'))->pluck('title')->all();
    expect($titles())->toContain('Baru', 'Info 8 hari', 'Peringatan 8 hari')->not->toContain('Sangat lama', 'Selesai'); // outside the window / resolved

    $this->artisan('notifications:prune')->assertSuccessful();
    expect(AppNotification::query()->pluck('title')->all())->toEqualCanonicalizing(['Baru', 'Peringatan 8 hari']); // old info, ancient and long-resolved are gone
    expect($fresh->fresh())->not->toBeNull();
});

it('answers the bell with a handful of queries however many permissions the user holds', function () {
    $outlet = (int) Outlet::query()->value('id');
    foreach (range(1, 40) as $i) {
        app(Notifier::class)->notify($outlet, 'x', "Pemberitahuan {$i}", null, null, 'info', ['manage-inventory']);
    }
    $owner = userWithRole('owner');
    DB::enableQueryLog();
    $res = $this->actingAs($owner)->getJson('/notifications')->assertOk();
    $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'app_notifications'))->count();

    expect($res->json('items'))->toHaveCount(30)->and($res->json('unread'))->toBe(40)->and($queries)->toBeLessThanOrEqual(2);
});

// ---------------------------------------------------------------- who did it

it('records who put the order in, who took the payment and who asked for the bill', function () {
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 0);
    $order = placeOrder($cashier);
    pay($order, $cashier);

    $placed = Activity::query()->where('event', 'order.placed')->latest('id')->first();
    expect($placed->causer_id)->toBe($cashier->id)->and($placed->properties['order'])->toBe($order->number);
    $paid = Activity::query()->where('event', 'payment.received')->latest('id')->first();
    expect($paid->causer_id)->toBe($cashier->id)->and($paid->properties['method'])->toBe('Tunai')->and($paid->properties['amount'])->toBe(44000);

    $other = placeOrder($cashier, tableId: Table::query()->skip(1)->value('id'));
    app(RequestBillAction::class)->handle($other, userWithRole('waiter'));
    expect($other->fresh()->bill_requested_by)->toBe(userWithRole('waiter')->id);
    expect(Activity::query()->where('event', 'bill.requested')->latest('id')->first()->causer_id)->toBe(userWithRole('waiter')->id);

    $this->actingAs($cashier)->getJson("/pos/orders/{$order->id}")->assertJsonPath('order.cashier', $cashier->name)->assertJsonPath('order.payments.0.by', $cashier->name)->assertJsonPath('order.rounds.0.by', $cashier->name);
    $this->actingAs($cashier)->getJson("/pos/orders/{$other->id}")->assertJsonPath('order.bill_requested_by', userWithRole('waiter')->name);
});

it('records who cooked and who closed the shift', function () {
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 0);
    $order = placeOrder($cashier);
    $item = $order->items->first();

    app(UpdateItemStatusAction::class)->handle($item, userWithRole('kitchen')); // new -> preparing
    app(UpdateItemStatusAction::class)->handle($item->fresh(), userWithRole('waiter')); // -> ready
    expect($item->fresh()->preparing_by)->toBe(userWithRole('kitchen')->id)->and($item->fresh()->ready_by)->toBe(userWithRole('waiter')->id);
    expect(Activity::query()->where('event', 'item.status')->pluck('causer_id')->all())->toBe([userWithRole('kitchen')->id, userWithRole('waiter')->id]);

    $owner = userWithRole('owner');
    $shift = Shift::query()->first();
    $this->actingAs($owner)->post('/shift/close', ['shift_id' => $shift->id, 'counted_cash' => 0, 'cash_left' => 0, 'close_key' => 'kk'])->assertSessionHasNoErrors();
    expect($shift->fresh()->user_id)->toBe($cashier->id)->and($shift->fresh()->closed_by)->toBe($owner->id);
});

it('records who split a bill and who consumed stock', function () {
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 0);
    $order = placeOrder($cashier);
    $this->actingAs($cashier)->postJson("/pos/orders/{$order->id}/split", ['mode' => 'equal', 'count' => 2])->assertOk();
    expect(Bill::query()->where('order_id', $order->id)->whereNotNull('created_by')->pluck('created_by')->unique()->all())->toBe([$cashier->id]);
    $this->actingAs($cashier)->deleteJson("/pos/orders/{$order->id}/split")->assertOk();
    expect(Activity::query()->where('event', 'bill.split_cancelled')->first()->causer_id)->toBe($cashier->id);

    setting('inventory.enabled', true);
    $beans = Ingredient::create(['outlet_id' => (int) Outlet::query()->value('id'), 'name' => 'Kopi', 'unit' => 'g', 'stock' => 100, 'avg_cost' => 10]);
    RecipeLine::create(['product_id' => product('Americano')->id, 'ingredient_id' => $beans->id, 'qty' => 18]);
    $this->actingAs($cashier)->postJson('/pos/orders', ['type' => 'takeaway', 'items' => [['product_id' => product('Americano')->id, 'qty' => 1]]])->assertCreated();
    expect(StockMovement::query()->where('type', 'sale')->value('user_id'))->toBe($cashier->id);
});

it('shows the activity log to those who may see it, filterable by person and group', function () {
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 0);
    pay(placeOrder($cashier), $cashier);
    $manager = userWithRole('manager');
    $d = BusinessDate::for();

    $res = $this->actingAs($manager)->get("/admin/activity?from={$d}&to={$d}")->assertOk()->assertInertia(fn ($p) => $p->component('admin/activity')->has('rows')->has('users')->has('groups'));
    $rows = collect($res->inertiaProps('rows'));
    expect($rows->pluck('event')->all())->toContain('Terima pembayaran', 'Input pesanan', 'Buka shift')->and($rows->where('event', 'Terima pembayaran')->first()['actor'])->toBe($cashier->name);
    expect($rows->where('event', 'Terima pembayaran')->first()['detail'])->toContain('Metode: Tunai')->toContain('Nominal: Rp 44.000');

    $only = collect($this->actingAs($manager)->get("/admin/activity?from={$d}&to={$d}&user_id={$manager->id}")->inertiaProps('rows'));
    expect($only->where('actor', $cashier->name))->toBeEmpty();
    $shifts = collect($this->actingAs($manager)->get("/admin/activity?from={$d}&to={$d}&group=shift")->inertiaProps('rows'));
    expect($shifts->pluck('event')->unique()->all())->toBe(['Buka shift']);

    $this->actingAs($manager)->get("/admin/activity/export?from={$d}&to={$d}&format=xlsx")->assertOk();
    $this->actingAs($cashier)->get('/admin/activity')->assertForbidden();
    expect(User::query()->count())->toBeGreaterThan(1);
});
