<?php

namespace App\Http\Presenters;

use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Payment\Models\Bill;
use App\Domain\Settings\Models\Outlet;
use App\Domain\Settings\SettingsRepository;

class ReceiptPresenter
{
    /** @return array<string, mixed> */
    public static function forBill(Bill $bill, bool $copy = false): array
    {
        $bill->loadMissing(['order.table', 'order.opener', 'items', 'charges.orderCharge', 'payments.method']);
        $order = $bill->order;
        $outlet = Outlet::find($order->outlet_id);
        $cfg = app(SettingsRepository::class);

        $rows = [];
        if ($bill->items->isNotEmpty()) {
            $items = OrderItem::query()->whereIn('id', $bill->items->pluck('order_item_id'))->get()->keyBy('id');
            foreach ($bill->items as $bi) {
                $i = $items->get($bi->order_item_id);
                if ($i === null) {
                    continue;
                }
                $rows[] = ['name' => $i->name_snapshot, 'qty' => $bi->qty, 'unit_price' => $i->unit_price, 'line_total' => $bi->line_total, 'modifiers' => collect($i->modifiers_snapshot ?? [])->pluck('name')->all()];
            }
        } elseif ($bill->split_mode === null) {
            foreach ($order->items()->whereIn('status', ['new', 'preparing', 'ready'])->whereNull('comp_type')->get() as $i) {
                $rows[] = ['name' => $i->name_snapshot, 'qty' => $i->qty, 'unit_price' => $i->unit_price, 'line_total' => $i->line_total, 'modifiers' => collect($i->modifiers_snapshot ?? [])->pluck('name')->all()];
            }
        } else {
            $rows[] = ['name' => "Porsi tagihan ({$bill->label})", 'qty' => 1, 'unit_price' => $bill->subtotal, 'line_total' => $bill->subtotal, 'modifiers' => []];
        }

        return [
            'copy' => $copy,
            'outlet' => ['name' => $outlet?->name, 'address' => $outlet?->address, 'npwp' => $cfg->get('receipt.show_npwp', true) ? $outlet?->npwp : null],
            'header' => ($h = (string) $cfg->get('receipt.header', '')) !== '' ? $h : null,
            'order_number' => $order->number,
            'label' => $bill->label,
            'is_split' => $bill->split_mode !== null,
            'date' => ($bill->paid_at ?? now())->setTimezone('Asia/Jakarta')->translatedFormat('d M Y H:i'),
            'cashier' => $cfg->get('receipt.show_cashier', true) ? ($bill->payments->first()?->receiver->name ?? $order->opener?->name) : null,
            'table' => $cfg->get('receipt.show_table', true) ? $order->table?->name : null,
            'call_number' => $cfg->get('receipt.show_call_number', true) ? $order->call_number : null,
            'items' => $rows,
            'subtotal' => $bill->subtotal,
            'discount_total' => $bill->discount_total,
            // Tax / service lines only exist when a rule is active (none at go-live).
            'charges' => $bill->charges->filter(fn ($c) => $c->amount > 0)->map(fn ($c) => [
                'name' => $c->orderCharge->name_snapshot, 'amount' => $c->amount, 'is_inclusive' => $c->orderCharge->is_inclusive,
            ])->values()->all(),
            'rounding' => $bill->rounding,
            'grand_total' => $bill->grand_total,
            'payments' => $bill->payments->map(fn ($p) => ['method' => $p->method->name, 'amount' => $p->amount, 'tendered' => $p->tendered, 'change' => $p->change])->all(),
            'change' => (int) $bill->payments->sum('change'),
            'footer' => app(SettingsRepository::class)->get('receipt.footer'),
            'public_url' => route('receipt.show', $bill->public_id),
            'show_link' => (bool) $cfg->get('receipt.show_link', false),
        ];
    }
}
