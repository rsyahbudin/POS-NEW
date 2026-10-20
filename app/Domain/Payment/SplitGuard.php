<?php

namespace App\Domain\Payment;

use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Payment\Actions\CancelSplitAction;
use App\Domain\Payment\Models\Bill;

/**
 * Edits (void, discount, move) interact with split bills:
 *  - no split: fine
 *  - split, nothing paid: the split is cancelled so it can be redone
 *  - split, some paid: only items in the unallocated bill may change
 */
class SplitGuard
{
    public function __construct(private CancelSplitAction $cancel) {}

    public function prepare(Order $order, ?OrderItem $item = null): void
    {
        $bills = Bill::query()->where('order_id', $order->id)->get();
        $split = $bills->whereNotNull('split_mode')->isNotEmpty();
        if (! $split) {
            return;
        }

        $anyPaid = $bills->contains(fn (Bill $b) => $b->paid_total > 0);
        if (! $anyPaid) {
            $this->cancel->handle($order);

            return;
        }

        if ($item) {
            $inUnallocated = $bills->firstWhere('is_unallocated', true)?->items()->where('order_item_id', $item->id)->exists();
            if ($inUnallocated) {
                return;
            }
        }

        throw new OrderException('Item ini sudah ada di tagihan yang dibayar dan terkunci.');
    }

    /**
     * Adding items to a split order: item-mode splits take them into the
     * unallocated bill; other split modes can only be redone before payment.
     */
    public function forNewItems(Order $order): void
    {
        $bills = Bill::query()->where('order_id', $order->id)->get();
        $mode = $bills->whereNotNull('split_mode')->first()?->split_mode;
        if ($mode === null || $mode === 'item') {
            return;
        }
        if ($bills->contains(fn (Bill $b) => $b->paid_total > 0)) {
            throw new OrderException('Split bagi rata/nominal sudah ada yang dibayar; item baru tidak bisa ditambahkan.');
        }
        $this->cancel->handle($order);
    }
}
