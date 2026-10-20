<?php

use App\Domain\Floor\Models\Table;
use App\Domain\Ordering\Actions\VoidItemAction;
use App\Http\Presenters\ReceiptPresenter;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

function itemsPayload(int $qty = 2): array
{
    return [['product_id' => product('Americano')->id, 'qty' => $qty]];
}

it('requires login for staff endpoints but not for the QR page', function () {
    $table = Table::query()->first();
    $this->getJson('/pos/state')->assertUnauthorized();
    $this->get("/m/{$table->qr_token}")->assertOk();
    $this->get('/m/not-a-real-token')->assertNotFound();
});

it('enforces the permission matrix on routes', function () {
    $this->actingAs(userWithRole('waiter'))->get('/admin/menu')->assertForbidden();
    $this->actingAs(userWithRole('kitchen'))->getJson('/pos/state')->assertForbidden();
    $this->actingAs(userWithRole('cashier'))->get('/admin/accounting')->assertForbidden();
    $this->actingAs(userWithRole('manager'))->post('/admin/accounting/journals', [])->assertForbidden();   // manager reads only
    $this->actingAs(userWithRole('manager'))->get('/admin/users')->assertForbidden();
    $this->actingAs(userWithRole('manager'))->get('/admin/menu')->assertOk();
    $this->actingAs(userWithRole('owner'))->get('/admin/users')->assertOk();
});

it('lets a waiter place an order but not take payment', function () {
    $waiter = userWithRole('waiter');
    $tableId = Table::query()->value('id');
    $res = $this->actingAs($waiter)->postJson('/pos/orders', ['type' => 'dine_in', 'table_id' => $tableId, 'items' => itemsPayload()])->assertCreated();
    $order = $res->json('order');
    expect($order['grand_total'])->toBe(44000);

    $this->actingAs($waiter)->postJson("/pos/orders/{$order['id']}/pay", [
        'bill_id' => $order['bills'][0]['id'], 'payment_method_id' => method('cash')->id, 'amount' => 44000, 'idempotency_key' => 'x',
    ])->assertForbidden();
});

it('ignores client-sent prices and totals', function () {
    $res = $this->actingAs(userWithRole('cashier'))->postJson('/pos/orders', [
        'type' => 'takeaway', 'items' => [['product_id' => product('Americano')->id, 'qty' => 1, 'unit_price' => 1, 'line_total' => 1]], 'grand_total' => 1,
    ])->assertCreated();

    expect($res->json('order.grand_total'))->toBe(22000);
});

it('returns a readable 422 for business-rule violations', function () {
    $this->actingAs(userWithRole('cashier'))->postJson('/pos/orders', ['type' => 'dine_in', 'items' => itemsPayload()])
        ->assertUnprocessable()->assertJsonPath('message', 'Pilih meja untuk dine-in.');
});

it('pays over HTTP idempotently and returns change', function () {
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 0);
    $order = $this->actingAs($cashier)->postJson('/pos/orders', ['type' => 'takeaway', 'items' => itemsPayload()])->json('order');
    $body = ['bill_id' => $order['bills'][0]['id'], 'payment_method_id' => method('cash')->id, 'amount' => 50000, 'idempotency_key' => (string) Str::uuid()];

    $a = $this->actingAs($cashier)->postJson("/pos/orders/{$order['id']}/pay", $body)->assertOk();
    $b = $this->actingAs($cashier)->postJson("/pos/orders/{$order['id']}/pay", $body)->assertOk();

    expect($a->json('change'))->toBe(6000)->and($b->json('payment_id'))->toBe($a->json('payment_id'))
        ->and($a->json('order.status'))->toBe('paid')->and(DB::table('payments')->count())->toBe(1);
});

it('voids with a manager PIN over HTTP', function () {
    $cashier = userWithRole('cashier');
    $order = $this->actingAs($cashier)->postJson('/pos/orders', ['type' => 'takeaway', 'items' => itemsPayload()])->json('order');
    $itemId = $order['rounds'][0]['items'][0]['id'];

    $this->actingAs($cashier)->postJson("/pos/items/{$itemId}/void", ['reason' => 'salah'])->assertUnprocessable();
    $this->actingAs($cashier)->postJson("/pos/items/{$itemId}/void", ['reason' => 'salah', 'pin' => '222222'])->assertOk()->assertJsonPath('order.grand_total', 0);
});

it('serves the QR flow end to end without a session: submit, confirm, status', function () {
    $table = Table::query()->first();
    $res = $this->postJson("/m/{$table->qr_token}/order", ['customer_name' => 'Rani', 'items' => itemsPayload(1)])->assertCreated();
    $roundId = $res->json('round_id');
    expect($res->json('status.rounds.0.status'))->toBe('Menunggu Konfirmasi');

    $this->actingAs(userWithRole('cashier'))->getJson('/pos/state')->assertOk()->assertJsonPath('pending_qr.0.customer_name', 'Rani');
    $this->actingAs(userWithRole('cashier'))->postJson("/pos/rounds/{$roundId}/confirm")->assertOk();

    $this->getJson("/m/{$table->qr_token}/status")->assertJsonPath('rounds.0.status', 'Diterima')->assertJsonPath('total', 22000);
});

it('validates QR payloads and rejects a missing name', function () {
    $table = Table::query()->first();
    $this->postJson("/m/{$table->qr_token}/order", ['customer_name' => '', 'items' => itemsPayload(1)])->assertUnprocessable();
    $this->postJson("/m/{$table->qr_token}/order", ['customer_name' => 'A', 'items' => []])->assertUnprocessable();
});

it('hides sold-out products from the QR menu but marks them for the cashier', function () {
    product('Americano')->update(['is_available' => false]);
    $table = Table::query()->first();

    $this->get("/m/{$table->qr_token}")->assertOk()->assertInertia(fn ($page) => $page->where('menu', fn ($menu) => collect($menu)->flatMap(fn ($c) => $c['products'])->pluck('name')->doesntContain('Americano')));
    $this->actingAs(userWithRole('cashier'))->get('/pos')->assertOk()->assertInertia(fn ($page) => $page->where('state.sold_out', [product('Americano')->id]));
});

it('toggles availability over HTTP', function () {
    $p = product('Americano');
    $this->actingAs(userWithRole('cashier'))->postJson("/pos/products/{$p->id}/availability", ['is_available' => false])->assertOk();
    expect($p->fresh()->is_available)->toBeFalse();
});

it('advances kitchen items and lists tickets for the station', function () {
    $order = placeOrder();
    $item = $order->items->first();
    $kitchen = userWithRole('kitchen');
    $stationId = $item->station_id;

    $this->actingAs($kitchen)->getJson("/kds/{$stationId}/tickets")->assertJsonCount(1, 'tickets');
    $this->actingAs($kitchen)->postJson("/kds/items/{$item->id}/advance")->assertJsonPath('status', 'preparing');
    $this->actingAs($kitchen)->postJson("/kds/items/{$item->id}/advance")->assertJsonPath('status', 'ready');
    $this->actingAs($kitchen)->postJson("/kds/items/{$item->id}/advance")->assertUnprocessable();
});

it('shows voided items on the KDS with their status instead of dropping them', function () {
    $order = placeOrder(items: [['product_id' => product('Americano')->id, 'qty' => 1], ['product_id' => product('Nasi Goreng')->id, 'qty' => 1]]);
    $item = $order->items->first();
    app(VoidItemAction::class)->handle($item, userWithRole('manager'), 'batal');

    $tickets = $this->actingAs(userWithRole('kitchen'))->getJson("/kds/{$item->station_id}/tickets")->json('tickets');
    $statuses = collect($tickets)->flatMap(fn ($t) => $t['items'])->pluck('status')->all();
    expect($statuses)->toContain('voided');
});

it('serves a public receipt by unguessable token and logs reprints as COPY', function () {
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 0);
    $order = placeOrder();
    pay($order);
    $bill = $order->bills()->first();

    $this->get("/r/{$bill->public_id}")->assertOk()->assertInertia(fn ($p) => $p->where('receipt.order_number', $order->number)->where('receipt.grand_total', 44000));
    $this->get('/r/01HZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();

    $this->actingAs($cashier)->postJson("/pos/bills/{$bill->id}/reprint")->assertJsonPath('receipt.copy', true);
    expect(Activity::query()->where('event', 'receipt.reprinted')->count())->toBe(1);
});

it('hides tax lines on the receipt when no tax is active (go-live default)', function () {
    openShiftFor(userWithRole('cashier'), 0);
    $order = placeOrder();
    pay($order);
    $receipt = ReceiptPresenter::forBill($order->bills()->first());
    expect($receipt['charges'])->toBe([]);
});

it('rate limits the public QR submit endpoint', function () {
    $table = Table::query()->first();
    $codes = [];
    foreach (range(1, 8) as $_) {
        $codes[] = $this->postJson("/m/{$table->qr_token}/order", ['customer_name' => 'A', 'items' => itemsPayload(1)])->status();
    }
    expect($codes)->toContain(429);
});

it('logs out and blocks deactivated staff', function () {
    $cashier = userWithRole('cashier');
    $cashier->update(['is_active' => false]);
    $this->actingAs($cashier)->get('/pos')->assertRedirect(route('login'));
});

it('logs the cashier out when the shift is closed', function () {
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 0);
    $this->actingAs($cashier)->post('/shift/close', ['counted_cash' => 0, 'cash_left' => 0, 'close_key' => 'k1'])->assertRedirect(route('login'));
    $this->assertGuest();
});

it('requires owner 2FA when enabled', function () {
    setting('security.require_owner_2fa', true);
    $this->actingAs(userWithRole('owner'))->get('/admin/menu')->assertRedirect(route('security.edit'));
    $this->actingAs(userWithRole('manager'))->get('/admin/menu')->assertOk();
});

it('formats receipt dates in Indonesian (20 Okt 2026)', function () {
    openShiftFor(userWithRole('cashier'), 0);
    $order = placeOrder();
    pay($order);

    $date = ReceiptPresenter::forBill($order->bills()->first())['date'];
    expect($date)->toMatch('/^\d{2} (Jan|Feb|Mar|Apr|Mei|Jun|Jul|Agu|Sep|Okt|Nov|Des) \d{4} \d{2}:\d{2}$/');
});
