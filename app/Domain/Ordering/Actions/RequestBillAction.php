<?php

namespace App\Domain\Ordering\Actions;

use App\Domain\Floor\Actions\SyncTableStatusAction;
use App\Domain\Ordering\Models\Order;
use App\Domain\Printing\PrintDispatcher;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Pre-bill: guest asks for the bill, table turns "Menunggu Bayar". */
class RequestBillAction
{
    public function __construct(private SyncTableStatusAction $syncTable) {}

    public function handle(Order $order, User $user): Order
    {
        return DB::transaction(function () use ($order) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->status->isEditable() && $order->bill_requested_at === null) {
                $order->update(['bill_requested_at' => now()]);
                $this->syncTable->handle($order->table_id);
            }
            if ($order->status->isEditable()) {
                PrintDispatcher::later(fn (PrintDispatcher $d) => $d->bill($order));
            }

            return $order;
        });
    }
}
