<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\BillService;
use App\Domain\Payment\Enums\BillStatus;
use App\Domain\Payment\Models\Bill;
use App\Domain\Pricing\Actions\RecalculateOrderAction;
use App\Domain\Pricing\PriceResult;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Splits an order into several bills: per item, equal parts, or free amounts.
 * Allowed only while no bill has been paid.
 */
class SplitBillAction
{
    public function __construct(
        private RecalculateOrderAction $recalculate,
        private CancelSplitAction $cancel,
        private BillService $bills,
    ) {}

    /**
     * @param  array<string, mixed>  $spec  item: parts=[{label, items:[{order_item_id, qty}]}]; equal: count, labels?; amount: parts=[{label, amount}]
     * @return array<int, Bill>
     */
    public function handle(Order $order, string $mode, array $spec, User $actor): array
    {
        return DB::transaction(function () use ($order, $mode, $spec, $actor) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (! $order->status->isEditable()) {
                throw new OrderException('Order sudah dikunci.');
            }
            if ($order->paid_total > 0) {
                throw new OrderException('Split tidak bisa diubah setelah ada tagihan yang dibayar.');
            }
            if ($order->rounds()->where('status', 'pending')->exists()) {
                throw new OrderException('Konfirmasi atau tolak pesanan QR dulu sebelum split.');
            }

            // Restart from a clean single bill so totals are fresh.
            $this->cancel->handle($order);
            $result = $this->recalculate->handle($order) ?? throw new OrderException('Total order tidak bisa dihitung.');
            $order->refresh();
            if ($order->grand_total <= 0) {
                throw new OrderException('Tidak ada tagihan untuk dipecah.');
            }

            $created = match ($mode) {
                'item' => $this->splitByItem($order, $spec),
                'equal' => $this->splitEqual($order, $result, $spec),
                'amount' => $this->splitByAmount($order, $result, $spec),
                default => throw new OrderException('Mode split tidak dikenal.'),
            };

            Audit::log('bill.split', $order, ['order' => $order->number, 'mode' => $mode, 'bills' => count($created)], $actor);

            return $created;
        });
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<int, Bill>
     */
    private function splitByItem(Order $order, array $spec): array
    {
        $parts = $spec['parts'] ?? [];
        if (count($parts) < 2) {
            throw new OrderException('Split butuh minimal 2 tagihan.');
        }

        $items = $order->items()->whereIn('status', ['new', 'preparing', 'ready'])->whereNull('comp_type')->get()->keyBy('id');
        $assigned = [];
        $perBill = [];
        foreach ($parts as $i => $part) {
            foreach ($part['items'] ?? [] as $row) {
                $id = (int) $row['order_item_id'];
                $qty = (int) $row['qty'];
                if (! isset($items[$id]) || $qty < 1) {
                    throw new OrderException('Item split tidak valid.');
                }
                $assigned[$id] = ($assigned[$id] ?? 0) + $qty;
                $perBill[$i][$id] = ($perBill[$i][$id] ?? 0) + $qty;
            }
            if (empty($perBill[$i])) {
                throw new OrderException('Setiap tagihan harus berisi item.');
            }
        }
        foreach ($items as $id => $item) {
            if (($assigned[$id] ?? 0) !== $item->qty) {
                throw new OrderException("Semua qty {$item->name_snapshot} harus dialokasikan ke tagihan.");
            }
        }

        // Fixed order discount is shared by each bill's net value.
        $fixed = $this->bills->fixedOrderDiscountTotal($order);
        $nets = [];
        foreach ($perBill as $i => $qtys) {
            $r = $this->bills->subsetTotals($order, $qtys, 0);
            $nets[$i] = max(1, $r->netSubtotal());
        }
        $fixedShares = $fixed > 0 ? Money::of($fixed)->allocate($nets) : array_fill_keys(array_keys($nets), 0);

        $this->bills->clearBills($order);
        $created = [];
        foreach ($parts as $i => $part) {
            $bill = $order->bills()->create(['label' => $part['label'] ?? 'Tamu '.($i + 1), 'split_mode' => 'item', 'status' => BillStatus::Unpaid]);
            $this->bills->writeBill($bill, $this->bills->subsetTotals($order, $perBill[$i], $fixedShares[$i]), $perBill[$i]);
            $created[] = $bill;
        }
        $this->bills->syncOrderFromBills($order);

        return $created;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<int, Bill>
     */
    private function splitEqual(Order $order, PriceResult $result, array $spec): array
    {
        $n = (int) ($spec['count'] ?? 0);
        if ($n < 2 || $n > 20) {
            throw new OrderException('Jumlah tagihan harus 2-20.');
        }

        // Remainder rupiah goes to the last bill.
        $parts = fn (int $amount) => $this->remainderToLast($amount, $n);

        return $this->writeWeighted($order, $result, 'equal', $parts($order->grand_total), is_array($spec['labels'] ?? null) ? array_values($spec['labels']) : [], $parts);
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<int, Bill>
     */
    private function splitByAmount(Order $order, PriceResult $result, array $spec): array
    {
        $amounts = array_map(fn ($p) => (int) $p['amount'], is_array($spec['parts'] ?? null) ? array_values($spec['parts']) : []);
        if (count($amounts) < 2) {
            throw new OrderException('Split butuh minimal 2 tagihan.');
        }
        if (min($amounts) <= 0) {
            throw new OrderException('Nominal tagihan harus lebih dari 0.');
        }
        if (array_sum($amounts) !== $order->grand_total) {
            throw new OrderException('Total nominal split harus sama dengan grand total ('.Money::formatRupiah($order->grand_total).').');
        }

        $labels = array_map(fn ($p) => isset($p['label']) ? (string) $p['label'] : null, array_values((array) $spec['parts']));

        return $this->writeWeighted($order, $result, 'amount', $amounts, $labels, fn (int $amount) => Money::of($amount)->allocate($amounts));
    }

    /**
     * @param  array<int, int>  $grands  grand total per bill
     * @param  array<int, string|null>  $labels
     * @param  callable(int): array<int|string, int>  $divide  splits an amount into one share per bill
     * @return array<int, Bill>
     */
    private function writeWeighted(Order $order, PriceResult $result, string $mode, array $grands, array $labels, callable $divide): array
    {
        $subtotals = $divide($result->subtotal);
        $discounts = $divide($result->discountTotal());
        $chargeShares = [];
        foreach ($result->charges as $idx => $c) {
            $chargeShares[$idx] = $divide($c['amount']);
        }

        $this->bills->clearBills($order);
        $created = [];
        foreach ($grands as $i => $grand) {
            $bill = $order->bills()->create(['label' => $labels[$i] ?? 'Tamu '.($i + 1), 'split_mode' => $mode, 'status' => BillStatus::Unpaid]);

            $charges = [];
            $exclusive = 0;
            foreach ($result->charges as $idx => $c) {
                $amount = $chargeShares[$idx][$i];
                $charges[] = [...$c, 'amount' => $amount];
                if (! $c['is_inclusive']) {
                    $exclusive += $amount;
                }
            }
            $raw = $subtotals[$i] - $discounts[$i] + $exclusive;

            $this->bills->writeBill($bill, new PriceResult(
                subtotal: $subtotals[$i],
                itemDiscountTotal: 0,
                orderDiscountTotal: $discounts[$i],
                charges: $charges,
                chargeTotal: $exclusive,
                inclusiveTotal: 0,
                rawTotal: $raw,
                rounding: $grand - $raw,
                grandTotal: $grand,
            ), null);
            $created[] = $bill;
        }
        $this->bills->syncOrderFromBills($order);

        return $created;
    }

    /** @return array<int, int> */
    private function remainderToLast(int $amount, int $n): array
    {
        $base = intdiv($amount, $n);
        $parts = array_fill(0, $n, $base);
        $parts[$n - 1] += $amount - $base * $n;

        return $parts;
    }
}
