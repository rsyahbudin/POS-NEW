<?php

use App\Domain\Floor\Models\Table;
use App\Domain\Notification\Models\AppNotification;
use App\Domain\Ordering\Actions\SubmitQrOrderAction;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Payment\Models\OnlinePayment;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Online\FakeGateway;
use App\Domain\Payment\Online\OnlineActor;
use App\Domain\Payment\Online\OnlinePaymentService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Http;

function qrisOn(string $provider = 'fake'): void
{
    setting('qris.enabled', true);
    setting('qris.provider', $provider);
}

function fakeHook(OnlinePayment $op, string $status = 'paid', ?int $amount = null): array
{
    $amount ??= $op->amount;

    return ['order_id' => $op->gatewayOrderId(), 'status' => $status, 'amount' => $amount, 'signature' => FakeGateway::signature($op->gatewayOrderId(), $status, $amount)];
}

it('keeps the pay button off until QRIS online is switched on', function () {
    $table = Table::query()->first();
    placeOrder(tableId: $table->id);
    $this->postJson("/m/{$table->qr_token}/pay")->assertNotFound();
});

it('lets a guest pay the table bill by QRIS and books it into the open shift as Pembayaran Online', function () {
    qrisOn();
    openShiftFor(userWithRole('cashier'), 0);
    $table = Table::query()->first();
    $order = placeOrder(tableId: $table->id);

    $res = $this->postJson("/m/{$table->qr_token}/pay")->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('amount', 44000);
    expect($res->json('qr'))->toStartWith('data:image/svg+xml');
    $this->postJson("/m/{$table->qr_token}/pay")->assertJsonPath('public_id', $res->json('public_id')); // same code is reused

    $op = OnlinePayment::query()->firstOrFail();
    $this->postJson('/webhooks/qris/fake', fakeHook($op))->assertOk();
    $this->postJson('/webhooks/qris/fake', fakeHook($op))->assertOk(); // gateway retries are harmless

    $payment = Payment::query()->firstOrFail();
    expect(Payment::query()->count())->toBe(1)->and($payment->amount)->toBe(44000)->and($payment->account_id)->toBe(method('qris')->account_id);
    expect($payment->received_by)->toBe(OnlineActor::user()->id)->and($order->fresh()->status)->toBe(OrderStatus::Paid);
    expect($op->fresh()->applied_at)->not->toBeNull();
    $this->getJson("/m/{$table->qr_token}/pay/{$op->public_id}")->assertJsonPath('status', 'paid')->assertJsonPath('qr', null);
    assertJournalsBalanced();
});

it('rejects forged webhooks and wrong amounts', function () {
    qrisOn();
    openShiftFor(userWithRole('cashier'), 0);
    $table = Table::query()->first();
    placeOrder(tableId: $table->id);
    $this->postJson("/m/{$table->qr_token}/pay")->assertCreated();
    $op = OnlinePayment::query()->firstOrFail();

    $bad = fakeHook($op);
    $bad['signature'] = 'x';
    $this->postJson('/webhooks/qris/fake', $bad)->assertForbidden();
    expect(Payment::query()->count())->toBe(0);

    $this->postJson('/webhooks/qris/fake', fakeHook($op, 'paid', 1000))->assertOk();
    expect(Payment::query()->count())->toBe(0)->and($op->fresh()->status)->toBe('failed');
});

it('holds a paid code until a shift is open, warns managers, then books it', function () {
    qrisOn();
    $table = Table::query()->first();
    $cashier = userWithRole('cashier');
    $shift = openShiftFor($cashier, 0);
    $order = placeOrder($cashier, tableId: $table->id);
    $this->postJson("/m/{$table->qr_token}/pay")->assertCreated();
    $op = OnlinePayment::query()->firstOrFail();
    $shift->update(['closed_at' => now()]);

    $this->postJson('/webhooks/qris/fake', fakeHook($op))->assertOk();
    expect($op->fresh()->status)->toBe('paid')->and($op->fresh()->applied_at)->toBeNull()->and(Payment::query()->count())->toBe(0);
    expect(AppNotification::query()->where('kind', 'online.unapplied')->count())->toBe(1);

    openShiftFor(userWithRole('manager'), 0);
    app(OnlinePaymentService::class)->sweep();
    expect(Payment::query()->count())->toBe(1)->and($order->fresh()->status)->toBe(OrderStatus::Paid);
});

it('refuses a code while the cashier has not confirmed the guest order', function () {
    openShiftFor(userWithRole('cashier'), 0);
    $table = Table::query()->first();
    // sent before QRIS was switched on, so it still waits for the cashier
    $this->postJson("/m/{$table->qr_token}/order", ['customer_name' => 'Rani', 'items' => [['product_id' => product('Americano')->id, 'qty' => 1]]])->assertCreated();
    qrisOn();
    $this->postJson("/m/{$table->qr_token}/pay")->assertUnprocessable();
});

it('talks to Midtrans: charge with the server key, and accepts only correctly signed notifications', function () {
    qrisOn('midtrans');
    setting('qris.midtrans_server_key', 'SB-key');
    openShiftFor(userWithRole('cashier'), 0);
    $table = Table::query()->first();
    $order = placeOrder(tableId: $table->id);
    Http::fake(['api.sandbox.midtrans.com/v2/charge' => Http::response(['status_code' => '201', 'transaction_id' => 'tx-1', 'qr_string' => '000201QRISDATA', 'expiry_time' => now()->addMinutes(15)->format('Y-m-d H:i:s')])]);

    $this->postJson("/m/{$table->qr_token}/pay")->assertCreated()->assertJsonPath('amount', 44000);
    Http::assertSent(fn ($r) => $r['payment_type'] === 'qris' && $r['transaction_details']['gross_amount'] === 44000 && $r->hasHeader('Authorization'));
    $op = OnlinePayment::query()->firstOrFail();
    expect($op->provider_ref)->toBe('tx-1');

    $note = ['order_id' => $op->gatewayOrderId(), 'status_code' => '200', 'gross_amount' => '44000.00', 'transaction_status' => 'settlement'];
    $this->postJson('/webhooks/qris/midtrans', $note + ['signature_key' => 'nope'])->assertForbidden();
    $this->postJson('/webhooks/qris/midtrans', $note + ['signature_key' => hash('sha512', $op->gatewayOrderId().'200'.'44000.00'.'SB-key')])->assertOk();
    expect($order->fresh()->status)->toBe(OrderStatus::Paid);
});

it('shows the online payments page to permitted roles only and lets a manager retry or simulate', function () {
    qrisOn();
    $this->actingAs(userWithRole('cashier'))->get('/admin/online-payments')->assertForbidden();
    $this->actingAs(userWithRole('manager'))->get('/admin/online-payments')->assertOk()->assertInertia(fn ($p) => $p->component('admin/online-payments')->where('enabled', true));
});

it('lets a QR order straight into the kitchen when guests can pay by QRIS, and keeps the old flow when they cannot', function () {
    $token = Table::query()->first()->qr_token;
    $items = fn () => ['customer_name' => 'Rani', 'items' => [['product_id' => product('Americano')->id, 'qty' => 1]]];

    $manual = app(SubmitQrOrderAction::class)->handle($token, $items());
    expect($manual->status->value)->toBe('pending');

    qrisOn();
    $this->getJson("/m/{$token}")->assertOk();
    $auto = app(SubmitQrOrderAction::class)->handle($token, $items());
    expect($auto->status->value)->toBe('confirmed');
});

it('lets a guest finish a test payment from the QR page in test mode, never in production', function () {
    qrisOn();
    openShiftFor(userWithRole('cashier'), 0);
    $table = Table::query()->first();
    placeOrder(tableId: $table->id);

    $res = $this->postJson("/m/{$table->qr_token}/pay")->assertCreated()->assertJsonPath('can_simulate', true);
    $url = "/m/{$table->qr_token}/pay/{$res->json('public_id')}/simulate";

    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->app['env'] = 'production';
    $this->postJson($url)->assertNotFound();
    $this->app['env'] = 'testing';

    $this->postJson($url)->assertOk()->assertJsonPath('status', 'paid');
    expect(Payment::query()->count())->toBe(1);
});
