<?php

namespace App\Domain\Ordering\Actions;

use App\Domain\Floor\Actions\SyncTableStatusAction;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Events\OrderItemStatusChanged;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\StockService;
use App\Domain\Payment\Enums\BillStatus;
use App\Models\User;
use App\Support\ApprovalGate;
use App\Support\Audit;
use App\Support\Realtime;
use Illuminate\Support\Facades\DB;

/** Cancels an unpaid order. Nothing is deleted; status and audit entry remain. */
class VoidOrderAction
{
    public function __construct(
        private ApprovalGate $gate,
        private StockService $stock,
        private SyncTableStatusAction $syncTable,
    ) {}

    public function handle(Order $order, User $actor, string $reason, ?string $pin = null): Order
    {
        if (trim($reason) === '') {
            throw new OrderException('Alasan void wajib diisi.');
        }
        $approver = $this->gate->resolve($actor, 'void', $pin);

        return DB::transaction(function () use ($order, $actor, $approver, $reason) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->status !== OrderStatus::Open || $order->paid_total > 0) {
                throw new OrderException('Order yang sudah dibayar tidak bisa di-void. Gunakan refund.');
            }

            foreach ($order->items()->whereIn('status', ['pending', 'new', 'preparing', 'ready'])->get() as $item) {
                $wasLive = $item->status->isLive();
                $item->update(['status' => 'voided', 'voided_by' => $actor->id, 'void_approved_by' => $approver->id, 'void_reason' => $reason, 'voided_at' => now()]);
                $this->stock->release($item);
                if ($wasLive) {
                    Realtime::dispatch(new OrderItemStatusChanged($order->outlet_id, $order->id, $item->id, $item->station_id, 'voided', $order->table?->public_id));
                }
            }
            $order->rounds()->where('status', 'pending')->update(['status' => 'rejected', 'reject_reason' => 'Order dibatalkan']);
            $order->bills()->update(['status' => BillStatus::Void]);

            $order->update([
                'status' => $order->status->transitionTo(OrderStatus::Cancelled),
                'cancel_reason' => $reason, 'cancelled_by' => $actor->id, 'closed_at' => now(),
                'subtotal' => 0, 'discount_total' => 0, 'charge_total' => 0, 'rounding' => 0, 'grand_total' => 0,
            ]);
            $this->syncTable->handle($order->table_id);

            Audit::log('order.voided', $order, ['order' => $order->number, 'reason' => $reason, 'approved_by' => $approver->id], $actor);

            return $order;
        });
    }
}
