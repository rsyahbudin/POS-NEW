<?php

namespace App\Http\Presenters;

use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Ordering\Models\OrderRound;

/** Shapes an order for the cashier / customer screens. Totals come from the server only. */
class OrderPresenter
{
    /** @return array<string, mixed> */
    public static function full(Order $order): array
    {
        $order->loadMissing(['table.area', 'rounds.items', 'charges', 'discounts', 'bills.items', 'bills.charges.orderCharge', 'payments.method', 'opener']);

        return [
            'id' => $order->id,
            'public_id' => $order->public_id,
            'number' => $order->number,
            'type' => $order->type->value,
            'platform' => $order->platform,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'table' => $order->table ? ['id' => $order->table->id, 'name' => $order->table->name, 'area' => $order->table->area->name] : null,
            'customer_name' => $order->customer_name,
            'call_number' => $order->call_number,
            'cashier' => $order->opener?->name,
            'subtotal' => $order->subtotal,
            'discount_total' => $order->discount_total,
            'charge_total' => $order->charge_total,
            'rounding' => $order->rounding,
            'grand_total' => $order->grand_total,
            'paid_total' => $order->paid_total,
            'refunded_total' => $order->refunded_total,
            'remaining' => $order->remaining(),
            'bill_requested' => $order->bill_requested_at !== null,
            'is_editable' => $order->status->isEditable(),
            'created_at' => $order->created_at?->toIso8601String(),
            'rounds' => $order->rounds->sortBy('id')->map(fn (OrderRound $r) => self::round($r))->values()->all(),
            'charges' => $order->charges->map(fn ($c) => ['name' => $c->name_snapshot, 'rate' => $c->rate, 'amount' => $c->amount, 'is_inclusive' => $c->is_inclusive])->all(),
            'discounts' => $order->discounts->whereNull('removed_at')->map(fn ($d) => [
                'id' => $d->id, 'item_id' => $d->order_item_id, 'type' => $d->type, 'value' => $d->value, 'amount' => $d->amount, 'reason' => $d->reason,
            ])->values()->all(),
            'bills' => $order->bills->map(fn ($b) => [
                'id' => $b->id, 'public_id' => $b->public_id, 'label' => $b->label, 'mode' => $b->split_mode, 'is_unallocated' => $b->is_unallocated,
                'subtotal' => $b->subtotal, 'discount_total' => $b->discount_total, 'charge_total' => $b->charge_total, 'rounding' => $b->rounding,
                'grand_total' => $b->grand_total, 'paid_total' => $b->paid_total, 'remaining' => $b->remaining(), 'status' => $b->status->value,
                'items' => $b->items->map(fn ($i) => ['order_item_id' => $i->order_item_id, 'qty' => $i->qty, 'line_total' => $i->line_total])->all(),
            ])->all(),
            'payments' => $order->payments->map(fn ($p) => [
                'id' => $p->id, 'bill_id' => $p->bill_id, 'method' => $p->method->name, 'method_type' => $p->method->type->value,
                'amount' => $p->amount, 'tendered' => $p->tendered, 'change' => $p->change, 'reference' => $p->reference,
                'refunded_total' => $p->refunded_total, 'created_at' => $p->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function round(OrderRound $r): array
    {
        return [
            'id' => $r->id,
            'source' => $r->source->value,
            'status' => $r->status->value,
            'customer_name' => $r->customer_name,
            'reject_reason' => $r->reject_reason,
            'created_at' => $r->created_at?->toIso8601String(),
            'items' => $r->items->map(fn (OrderItem $i) => self::item($i))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function item(OrderItem $i): array
    {
        return [
            'id' => $i->id, 'name' => $i->name_snapshot, 'qty' => $i->qty, 'unit_price' => $i->unit_price, 'line_total' => $i->line_total,
            'modifiers' => collect($i->modifiers_snapshot ?? [])->pluck('name')->all(),
            'note' => $i->note, 'status' => $i->status->value, 'comp_type' => $i->comp_type?->value, 'void_reason' => $i->void_reason,
        ];
    }
}
