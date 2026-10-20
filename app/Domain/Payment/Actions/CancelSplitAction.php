<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Enums\BillStatus;
use App\Domain\Payment\Models\Bill;
use App\Domain\Payment\Models\BillCharge;
use App\Domain\Payment\Models\BillItem;
use App\Domain\Pricing\Actions\RecalculateOrderAction;
use Illuminate\Support\Facades\DB;

/** Drops split bills and returns the order to one default bill. Only before any payment. */
class CancelSplitAction
{
    public function handle(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $bills = Bill::query()->where('order_id', $order->id)->get();
            if ($bills->contains(fn (Bill $b) => $b->split_mode !== null) === false) {
                return;
            }
            if ($bills->contains(fn (Bill $b) => $b->paid_total > 0 || $b->status === BillStatus::Paid)) {
                throw new OrderException('Split tidak bisa dibatalkan karena sudah ada tagihan yang dibayar.');
            }

            BillItem::query()->whereIn('bill_id', $bills->pluck('id'))->delete();
            BillCharge::query()->whereIn('bill_id', $bills->pluck('id'))->delete();
            Bill::query()->where('order_id', $order->id)->delete();

            // Order-level snapshots are rebuilt by a normal recalculation.
            app(RecalculateOrderAction::class)->handle($order);
        });
    }
}
