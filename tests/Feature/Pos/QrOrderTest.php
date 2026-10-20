<?php

use App\Domain\Floor\Models\Table;
use App\Domain\Ordering\Actions\ConfirmRoundAction;
use App\Domain\Ordering\Actions\RejectRoundAction;
use App\Domain\Ordering\Actions\SubmitQrOrderAction;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Enums\RoundStatus;
use App\Domain\Ordering\Exceptions\OrderException;

function qr(Table $table, ?array $items = null, string $name = 'Dina')
{
    return app(SubmitQrOrderAction::class)->handle($table->qr_token, [
        'customer_name' => $name,
        'items' => $items ?? [['product_id' => product('Americano')->id, 'qty' => 2]],
    ]);
}

it('lands a QR order as pending and keeps it away from the kitchen', function () {
    $table = Table::query()->first();
    $round = qr($table);

    expect($round->status)->toBe(RoundStatus::Pending)
        ->and($round->items->pluck('status')->map->value->unique()->all())->toBe(['pending'])
        ->and($round->order->subtotal)->toBe(0)
        ->and($table->fresh()->status->value)->toBe('empty');
});

it('requires a call name', function () {
    expect(fn () => qr(Table::query()->first(), name: '  '))->toThrow(OrderException::class, 'Nama');
});

it('does not trust client prices: server rebuilds them', function () {
    $round = qr(Table::query()->first());
    expect($round->items->first()->unit_price)->toBe(22000);
});

it('confirms into the kitchen and recalculates the order', function () {
    $table = Table::query()->first();
    $round = qr($table);
    app(ConfirmRoundAction::class)->handle($round, userWithRole('cashier'));

    expect($round->fresh()->status)->toBe(RoundStatus::Confirmed)
        ->and($round->order->fresh()->subtotal)->toBe(44000)
        ->and($round->items()->first()->status->value)->toBe('new')
        ->and($table->fresh()->status->value)->toBe('occupied');
});

it('lets the cashier change qty or remove items before confirming (e.g. sold out)', function () {
    $round = qr(Table::query()->first(), [['product_id' => product('Americano')->id, 'qty' => 2], ['product_id' => product('Nasi Goreng')->id, 'qty' => 1]]);
    [$a, $b] = $round->items->pluck('id')->all();

    app(ConfirmRoundAction::class)->handle($round, userWithRole('cashier'), [$a => 1, $b => 0]);
    expect($round->order->fresh()->subtotal)->toBe(22000)->and($round->items()->find($b)->status->value)->toBe('rejected');
});

it('adds a confirmed QR round to the table order that is already open', function () {
    $table = Table::query()->first();
    $order = placeOrder(tableId: $table->id);
    $round = qr($table);
    expect($round->order_id)->toBe($order->id);

    app(ConfirmRoundAction::class)->handle($round, userWithRole('cashier'));
    expect($order->fresh()->rounds()->count())->toBe(2)->and($order->fresh()->subtotal)->toBe(88000);
});

it('rejects with a mandatory reason and cancels an order that only had that round', function () {
    $round = qr(Table::query()->first());
    expect(fn () => app(RejectRoundAction::class)->handle($round, userWithRole('cashier'), ' '))->toThrow(OrderException::class);

    app(RejectRoundAction::class)->handle($round, userWithRole('cashier'), 'menu habis');
    expect($round->fresh()->reject_reason)->toBe('menu habis')->and($round->order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

it('cannot process a round twice', function () {
    $round = qr(Table::query()->first());
    app(ConfirmRoundAction::class)->handle($round, userWithRole('cashier'));
    expect(fn () => app(ConfirmRoundAction::class)->handle($round, userWithRole('cashier')))->toThrow(OrderException::class);
});

it('limits QR submissions to 5 per table per 10 minutes', function () {
    $table = Table::query()->first();
    foreach (range(1, 5) as $_) {
        qr($table);
    }
    expect(fn () => qr($table))->toThrow(OrderException::class, 'Terlalu banyak');
});

it('refuses disabled or unknown QR tokens, and a regenerated token invalidates the old one', function () {
    $table = Table::query()->first();
    $old = $table->qr_token;
    $table->regenerateToken();

    expect(fn () => app(SubmitQrOrderAction::class)->handle($old, ['customer_name' => 'A', 'items' => [['product_id' => product('Americano')->id, 'qty' => 1]]]))->toThrow(OrderException::class);

    $table->fresh()->update(['qr_enabled' => false]);
    expect(fn () => qr($table->fresh()))->toThrow(OrderException::class, 'tidak aktif');
});

it('reserves portion stock only when confirmed', function () {
    $croissant = product('Croissant');
    $round = qr(Table::query()->first(), [['product_id' => $croissant->id, 'qty' => 5]]);
    expect($croissant->fresh()->stock_qty)->toBe(20);

    app(ConfirmRoundAction::class)->handle($round, userWithRole('cashier'));
    expect($croissant->fresh()->stock_qty)->toBe(15);
});
