<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\BillService;
use App\Domain\Payment\Models\Bill;
use App\Domain\Payment\Models\BillItem;
use Illuminate\Support\Facades\DB;

/** Moves qty from the "unallocated" bill into an unpaid bill and reprices that bill. */
class AllocateItemsAction
{
    public function __construct(private BillService $bills) {}

    /** @param  array<int, int>  $itemQty  order_item_id => qty */
    public function handle(Bill $target, array $itemQty): void
    {
        DB::transaction(function () use ($target, $itemQty) {
            $order = Order::query()->lockForUpdate()->findOrFail($target->order_id);
            $target = Bill::query()->lockForUpdate()->findOrFail($target->id);
            if ($target->paid_total > 0 || $target->is_unallocated || $target->split_mode !== 'item') {
                throw new OrderException('Tagihan tujuan tidak bisa diubah.');
            }

            $unallocated = Bill::query()->where('order_id', $order->id)->where('is_unallocated', true)->first();
            if (! $unallocated) {
                throw new OrderException('Tidak ada item yang belum dialokasikan.');
            }
            $pool = BillItem::query()->where('bill_id', $unallocated->id)->pluck('qty', 'order_item_id')->all();

            $merged = BillItem::query()->where('bill_id', $target->id)->pluck('qty', 'order_item_id')->all();
            foreach ($itemQty as $id => $qty) {
                if ($qty < 1 || ($pool[$id] ?? 0) < $qty) {
                    throw new OrderException('Qty melebihi item yang belum dialokasikan.');
                }
                $merged[$id] = ($merged[$id] ?? 0) + $qty;
            }

            $this->bills->writeBill($target, $this->bills->subsetTotals($order, $merged), $merged);
            $this->bills->reconcileSplit($order);
        });
    }
}
