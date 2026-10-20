<?php

namespace App\Domain\Ordering\Actions;

use App\Domain\Floor\Actions\SyncTableStatusAction;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\Models\Order;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

class MoveTableAction
{
    public function __construct(private SyncTableStatusAction $syncTable) {}

    public function handle(Order $order, int $newTableId, User $actor): Order
    {
        return DB::transaction(function () use ($order, $newTableId, $actor) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (! $order->status->isEditable() || $order->table_id === null) {
                throw new OrderException('Order ini tidak bisa dipindah.');
            }
            $busy = Order::query()->where('table_id', $newTableId)->whereIn('status', ['open', 'partially_paid'])->lockForUpdate()->exists();
            if ($busy) {
                throw new OrderException('Meja tujuan masih terisi. Gunakan gabung meja.');
            }

            $old = $order->table_id;
            $order->update(['table_id' => $newTableId]);
            $this->syncTable->handle($old);
            $this->syncTable->handle($newTableId);
            Audit::log('table.moved', $order, ['order' => $order->number, 'from' => $old, 'to' => $newTableId], $actor);

            return $order;
        });
    }
}
