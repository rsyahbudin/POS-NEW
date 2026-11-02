<?php

namespace App\Domain\Reporting;

use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Settings\Models\Outlet;
use App\Domain\Settings\OutletContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * "Sales Recapitulation Detail": one row per sold item, with the order, table, time, payment and the money columns, in the
 * layout the back office already uses from the previous POS. Service charge, tax and bill discount are shared over the
 * items of an order in proportion to what each item sold for, so every row adds up and the rows add up to the order.
 */
class SalesDetailReport
{
    public const HEADERS = [
        'Sales Number', 'Bill Number', 'Sales Type', 'Batch Order', 'Table Section', 'Table Name', 'Sales Date', 'Sales Date In', 'Sales Date Out', 'Branch', 'Visit Purpose',
        'Customer Name', 'Payment Method', 'Received By', 'Menu Category', 'Menu', 'Menu Code', 'Menu Notes', 'Order Mode', 'Qty', 'Price', 'Subtotal', 'Discount',
        'Service Charge', 'Tax', 'Total', 'Nett Sales', 'DPP', 'Bill Discount', 'Total After Bill Discount', 'Waiter', 'Order Time',
    ];

    private const VISIT = ['dine_in' => 'DINE IN', 'takeaway' => 'TAKE AWAY', 'online' => 'ONLINE'];

    public function __construct(private OutletContext $outlet) {}

    /**
     * @return array{rows: list<list<mixed>>, rounding: int, refunds: int, orders: int}
     */
    public function build(string $from, string $to): array
    {
        $outletId = $this->outlet->id();
        $name = (string) Outlet::query()->whereKey($outletId)->value('name');
        $rows = [];
        $rounding = 0;
        $refunds = 0;
        $orders = 0;

        Order::query()->where('outlet_id', $outletId)->whereIn('status', ['paid', 'refunded'])->whereBetween('business_date', [$from, $to])
            ->with(['table.area', 'charges', 'payments.method', 'payments.receiver', 'items.round.creator', 'items.product.category'])
            ->orderBy('business_date')->orderBy('closed_at')->orderBy('id')
            ->chunkById(200, function ($chunk) use (&$rows, &$rounding, &$refunds, &$orders, $name) {
                foreach ($chunk as $order) {
                    $orders++;
                    $rounding += $order->rounding;
                    $refunds += $order->refunded_total;
                    foreach ($this->lines($order, $name) as $line) {
                        $rows[] = $line;
                    }
                }
            });

        return ['rows' => $rows, 'rounding' => $rounding, 'refunds' => $refunds, 'orders' => $orders];
    }

    /** @return list<list<mixed>> */
    private function lines(Order $order, string $branch): array
    {
        $items = $order->items->filter(fn (OrderItem $i) => $i->voided_at === null && in_array($i->status->value, ['new', 'preparing', 'ready'], true))->sortBy('id')->values();
        if ($items->isEmpty()) {
            return [];
        }
        // what each item really sold for: a complimentary item is shown at its price, discounted down to nothing
        $weights = array_values($items->map(fn (OrderItem $i): int => $i->comp_type === null ? max(0, (int) $i->line_total) : 0)->all());
        $service = $order->charges->filter(fn ($c) => $c->kind === 'service');
        $tax = $order->charges->filter(fn ($c) => $c->kind === 'tax');
        $serviceShare = self::share((int) $service->sum('amount'), $weights);
        $taxShare = self::share((int) $tax->sum('amount'), $weights);
        $billShare = self::share((int) $order->discount_total, $weights);
        $serviceOnTop = (bool) $service->contains(fn ($c) => ! $c->is_inclusive);
        $taxOnTop = (bool) $tax->contains(fn ($c) => ! $c->is_inclusive);
        $hasTax = $tax->isNotEmpty();

        $rounds = $items->pluck('order_round_id')->unique()->values()->flip();
        $methods = $order->payments->map(fn ($p) => $p->method->name ?? null)->filter()->unique()->implode(', ');
        $in = $this->local($order->created_at);
        $receivers = $order->payments->map(fn ($p) => $p->receiver->name ?? null)->filter()->unique()->implode(', ');
        $out = $this->local($order->closed_at);
        $out ??= $in;
        $type = self::VISIT[$order->type->value];
        $sales = $order->status->value === 'refunded' ? 'Sales (Refund)' : 'Sales';
        $out_rows = [];

        foreach ($items as $n => $item) {
            $comp = $item->comp_type !== null;
            $subtotal = (int) $item->line_total;
            $discount = $comp ? $subtotal : 0;
            $sc = (int) $serviceShare[$n];
            $tx = (int) $taxShare[$n];
            $bill = (int) $billShare[$n];
            $total = $subtotal - $discount + ($serviceOnTop ? $sc : 0) + ($taxOnTop ? $tx : 0);
            // Net sales never holds tax or service: a charge already inside the menu price is taken back out, one added on top was never in it.
            $inclusive = ($serviceOnTop ? 0 : $sc) + ($taxOnTop ? 0 : $tx);
            $nett = $subtotal - $discount - $inclusive;
            $round = $item->round;
            $notes = collect($item->modifiers_snapshot ?? [])->pluck('name')->filter()->push($item->note)->filter()->implode(', ');
            $categoryName = strtoupper((string) ($item->product->category->name ?? ''));

            $out_rows[] = [
                strtoupper((string) $order->public_id), $order->number, $sales, (int) ($rounds[$item->order_round_id] ?? 0) + 1,
                $order->table->area->name ?? '', $order->table->name ?? ($order->call_number ?? ''),
                CarbonImmutable::parse($order->business_date)->toDateString(), $in, $out, $branch, $type,
                $order->customer_name ?: ($round->customer_name ?: '-'), $methods, $receivers, $categoryName, $item->name_snapshot, (string) ($item->product->sku ?? ''),
                $notes, $round->source->value === 'qr' ? 'QR' : 'POS', (int) $item->qty, (int) $item->unit_price, $subtotal, $discount, $sc, $tx, $total, $nett,
                $hasTax ? max(0, $nett - $bill) : 0, $bill, $total - $bill, $round->source->value === 'qr' ? '' : ($round->creator->name ?? ''), $this->local($round->created_at ?? $order->created_at),
            ];
        }

        return $out_rows;
    }

    private function local(?CarbonInterface $at): ?CarbonImmutable
    {
        return $at === null ? null : CarbonImmutable::parse($at)->setTimezone('Asia/Jakarta');
    }

    /**
     * Splits an amount over weights, whole rupiah, largest remainder first, so the pieces add up to the amount.
     *
     * @param  list<int>  $weights
     * @return list<int>
     */
    public static function share(int $amount, array $weights): array
    {
        $sum = array_sum($weights);
        if ($amount === 0 || $sum <= 0) {
            return array_fill(0, count($weights), 0);
        }
        $parts = [];
        $given = 0;
        foreach ($weights as $i => $w) {
            $exact = $amount * $w / $sum;
            $parts[$i] = [(int) floor($exact), $exact - floor($exact)];
            $given += $parts[$i][0];
        }
        $left = $amount - $given;
        $order = array_keys($parts);
        usort($order, fn ($a, $b) => $parts[$b][1] <=> $parts[$a][1]);
        foreach ($order as $i) {
            if ($left <= 0) {
                break;
            }
            if ($weights[$i] > 0) {
                $parts[$i][0]++;
                $left--;
            }
        }

        return array_values(array_map(fn (array $p): int => $p[0], $parts));
    }
}
