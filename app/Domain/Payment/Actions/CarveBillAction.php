<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Payment\BillService;
use App\Domain\Payment\Enums\BillStatus;
use App\Domain\Payment\Models\Bill;
use App\Domain\Payment\Models\BillItem;
use App\Domain\Pricing\PriceResult;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * "Pay for these items": the cashier ticks items (and quantities) and pays just
 * that part. A new bill is carved out of the unpaid bills; what is left stays
 * payable as "Sisa". Works on orders that are not split or split per item.
 */
class CarveBillAction
{
    public function __construct(private BillService $bills) {}

    /** Server-side price of the selection (tax, service, discount, rounding), nothing is saved. */
    public function preview(Order $order, array $itemQty): PriceResult
    {
        $clean = $this->validate($order, $itemQty);

        return $this->bills->subsetTotals($order, $clean);
    }

    /**
     * @param  array<int, int>  $itemQty  order_item_id => qty to pay now
     */
    public function handle(Order $order, array $itemQty, User $actor, ?string $label = null): Bill
    {
        return DB::transaction(function () use ($order, $itemQty, $actor, $label) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $selected = $this->validate($order, $itemQty);

            $bills = Bill::query()->where('order_id', $order->id)->orderBy('id')->get();
            $isSplit = $bills->contains(fn (Bill $b) => $b->split_mode !== null);

            // allocation of live items per unpaid bill
            $items = $this->liveItems($order);
            if (! $isSplit) {
                $remaining = [];
                foreach ($items as $id => $item) {
                    $rest = $item->qty - ($selected[$id] ?? 0);
                    if ($rest > 0) {
                        $remaining[$id] = $rest;
                    }
                }
                if ($remaining === []) {
                    return $bills->first() ?? throw new OrderException('Tagihan tidak ditemukan.');
                }

                $this->bills->clearBills($order);
                $new = $order->bills()->create(['label' => $label ?: 'Pilihan 1', 'split_mode' => 'item', 'status' => BillStatus::Unpaid]);
                $rest = $order->bills()->create(['label' => 'Sisa', 'split_mode' => 'item', 'status' => BillStatus::Unpaid]);
                $this->reprice($order, [$new->id => $selected, $rest->id => $remaining], [$new->id => $new, $rest->id => $rest]);
            } else {
                $unpaid = $bills->filter(fn (Bill $b) => $b->status === BillStatus::Unpaid && $b->paid_total === 0)->values();
                $alloc = [];
                foreach ($unpaid as $b) {
                    $alloc[$b->id] = BillItem::query()->where('bill_id', $b->id)->pluck('qty', 'order_item_id')->map(fn ($q) => (int) $q)->all();
                }

                // already exactly one unpaid bill with the same content? just pay it
                foreach ($alloc as $billId => $map) {
                    if ($map === $selected) {
                        return $unpaid->firstWhere('id', $billId) ?? throw new OrderException('Tagihan tidak ditemukan.');
                    }
                }

                $touched = [];
                foreach ($selected as $itemId => $need) {
                    foreach ($alloc as $billId => $map) {
                        $take = min($need, $map[$itemId] ?? 0);
                        if ($take > 0) {
                            $alloc[$billId][$itemId] -= $take;
                            if ($alloc[$billId][$itemId] === 0) {
                                unset($alloc[$billId][$itemId]);
                            }
                            $touched[$billId] = true;
                            $need -= $take;
                        }
                        if ($need === 0) {
                            break;
                        }
                    }
                }

                $new = $order->bills()->create(['label' => $label ?: 'Pilihan '.($bills->where('split_mode', 'item')->count() + 1), 'split_mode' => 'item', 'status' => BillStatus::Unpaid]);
                $targets = [$new->id => $selected];
                $models = [$new->id => $new];
                foreach (array_keys($touched) as $billId) {
                    $bill = $unpaid->firstWhere('id', $billId);
                    if ($bill === null) {
                        continue;
                    }
                    if ($alloc[$billId] === []) {
                        BillItem::query()->where('bill_id', $billId)->delete();
                        $bill->charges()->delete();
                        $bill->delete();

                        continue;
                    }
                    // leftovers become a normal payable bill, even if they were "unallocated"
                    $bill->update(['is_unallocated' => false, 'label' => $bill->is_unallocated || $bill->label === 'Belum dialokasikan' ? 'Sisa' : $bill->label]);
                    $targets[$billId] = $alloc[$billId];
                    $models[$billId] = $bill;
                }
                $this->reprice($order, $targets, $models);
            }

            $this->bills->syncOrderFromBills($order);
            Audit::log('bill.carved', $order, ['order' => $order->number, 'items' => $selected], $actor);

            return $new;
        });
    }

    /**
     * @param  array<int, array<int, int>>  $targets  billId => [itemId => qty]
     * @param  array<int, Bill>  $models
     */
    private function reprice(Order $order, array $targets, array $models): void
    {
        $fixed = $this->bills->fixedOrderDiscountTotal($order);
        $weights = [];
        foreach ($targets as $billId => $map) {
            $weights[$billId] = max(1, $this->bills->subsetTotals($order, $map)->netSubtotal());
        }

        // Bills already paid keep their totals; they still take their share of a fixed order discount.
        $paidNet = 0;
        foreach (Bill::query()->where('order_id', $order->id)->where('paid_total', '>', 0)->get() as $paid) {
            $paidMap = BillItem::query()->where('bill_id', $paid->id)->pluck('qty', 'order_item_id')->map(fn ($q) => (int) $q)->all();
            $paidNet += $paidMap === [] ? 0 : $this->bills->subsetTotals($order, $paidMap)->netSubtotal();
        }

        $shares = array_fill_keys(array_keys($weights), 0);
        if ($fixed > 0) {
            $all = $paidNet > 0 ? ['paid' => $paidNet] + $weights : $weights;
            $split = Money::of($fixed)->allocate($all);
            foreach (array_keys($weights) as $billId) {
                $shares[$billId] = $split[$billId];
            }
        }

        foreach ($targets as $billId => $map) {
            $this->bills->writeBill($models[$billId], $this->bills->subsetTotals($order, $map, $shares[$billId]), $map);
        }
    }

    /** @return array<int, OrderItem> live, non-complimentary items keyed by id */
    private function liveItems(Order $order): array
    {
        return OrderItem::query()->where('order_id', $order->id)->whereIn('status', ['new', 'preparing', 'ready'])->whereNull('comp_type')
            ->get()->keyBy('id')->all();
    }

    /**
     * @param  array<int, int>  $itemQty
     * @return array<int, int>
     */
    private function validate(Order $order, array $itemQty): array
    {
        if (! $order->status->isEditable()) {
            throw new OrderException('Order sudah dikunci.');
        }
        if ($order->rounds()->where('status', 'pending')->exists()) {
            throw new OrderException('Konfirmasi atau tolak pesanan QR dulu.');
        }

        $bills = Bill::query()->where('order_id', $order->id)->get();
        if ($bills->contains(fn (Bill $b) => $b->split_mode !== null && $b->split_mode !== 'item')) {
            throw new OrderException('Order ini dipecah bagi rata/nominal. Batalkan split dulu untuk memilih per item.');
        }

        if (! $bills->contains(fn (Bill $b) => $b->split_mode !== null) && $bills->contains(fn (Bill $b) => $b->paid_total > 0)) {
            throw new OrderException('Tagihan sudah dibayar sebagian. Selesaikan pembayarannya dulu.');
        }

        $items = $this->liveItems($order);
        $paidQty = BillItem::query()->whereIn('bill_id', $bills->filter(fn (Bill $b) => $b->paid_total > 0 || $b->status === BillStatus::Paid)->pluck('id'))
            ->selectRaw('order_item_id, SUM(qty) as qty')->groupBy('order_item_id')->pluck('qty', 'order_item_id');

        $clean = [];
        foreach ($itemQty as $id => $qty) {
            $qty = (int) $qty;
            if ($qty <= 0) {
                continue;
            }
            $item = $items[(int) $id] ?? throw new OrderException('Item tidak valid atau sudah tidak aktif.');
            $available = $item->qty - (int) ($paidQty[$item->id] ?? 0);
            if ($qty > $available) {
                throw new OrderException("{$item->name_snapshot}: tersisa {$available} yang belum dibayar.");
            }
            $clean[(int) $id] = $qty;
        }
        if ($clean === []) {
            throw new OrderException('Pilih minimal satu item.');
        }

        return $clean;
    }
}
