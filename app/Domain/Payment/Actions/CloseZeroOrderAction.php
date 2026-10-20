<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Floor\Actions\SyncTableStatusAction;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Events\OrderPaid;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Enums\BillStatus;
use App\Models\User;
use App\Support\Audit;
use App\Support\Realtime;
use Illuminate\Support\Facades\DB;

/** Orders worth Rp0 (all complimentary / staff meal) close without a payment or a journal. */
class CloseZeroOrderAction
{
    public function __construct(private SyncTableStatusAction $syncTable) {}

    public function handle(Order $order, User $user): Order
    {
        return DB::transaction(function () use ($order, $user) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->grand_total !== 0 || ! $order->status->isEditable() || $order->paid_total > 0) {
                throw new OrderException('Order ini tidak bisa ditutup tanpa pembayaran.');
            }
            if (! $order->items()->whereIn('status', ['new', 'preparing', 'ready'])->exists()) {
                throw new OrderException('Order tidak punya item aktif.');
            }

            $order->bills()->update(['status' => BillStatus::Paid, 'paid_at' => now()]);
            $order->update(['status' => $order->status->transitionTo(OrderStatus::Paid), 'closed_at' => now()]);
            $this->syncTable->handle($order->table_id);
            Audit::log('order.closed_zero', $order, ['order' => $order->number], $user);
            Realtime::dispatch(new OrderPaid($order->outlet_id, $order->id, $order->table?->public_id));

            return $order;
        });
    }
}
