<?php

namespace App\Domain\Ordering\Actions;

use App\Domain\Floor\Actions\SyncTableStatusAction;
use App\Domain\Ordering\Events\OrderItemStatusChanged;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Ordering\StockService;
use App\Domain\Payment\SplitGuard;
use App\Domain\Pricing\Actions\RecalculateOrderAction;
use App\Models\User;
use App\Support\ApprovalGate;
use App\Support\Audit;
use App\Support\Realtime;
use Illuminate\Support\Facades\DB;

class VoidItemAction
{
    public function __construct(
        private ApprovalGate $gate,
        private StockService $stock,
        private RecalculateOrderAction $recalculate,
        private SyncTableStatusAction $syncTable,
        private SplitGuard $split,
    ) {}

    public function handle(OrderItem $item, User $actor, string $reason, ?string $pin = null): OrderItem
    {
        if (trim($reason) === '') {
            throw new OrderException('Alasan void wajib diisi.');
        }
        $approver = $this->gate->resolveCancel($actor, $pin);

        return DB::transaction(function () use ($item, $actor, $approver, $reason) {
            $order = Order::query()->lockForUpdate()->findOrFail($item->order_id);
            $item = OrderItem::query()->lockForUpdate()->findOrFail($item->id);

            if (! $order->status->isEditable()) {
                throw new OrderException('Order sudah dikunci. Gunakan refund.');
            }
            if (! $item->status->isLive()) {
                throw new OrderException('Item ini tidak aktif.');
            }

            $this->split->prepare($order, $item);

            $item->update([
                'status' => 'voided',
                'voided_by' => $actor->id,
                'void_approved_by' => $approver->id,
                'void_reason' => $reason,
                'voided_at' => now(),
            ]);
            $this->stock->release($item);
            $this->recalculate->handle($order);
            $this->syncTable->handle($order->table_id);

            Audit::log('item.voided', $item, [
                'order' => $order->number, 'item' => $item->name_snapshot, 'qty' => $item->qty,
                'value' => $item->line_total, 'reason' => $reason, 'approved_by' => $approver->id,
            ], $actor);

            Realtime::dispatch(new OrderItemStatusChanged($order->outlet_id, $order->id, $item->id, $item->station_id, 'voided', $order->table?->public_id));

            return $item;
        });
    }
}
