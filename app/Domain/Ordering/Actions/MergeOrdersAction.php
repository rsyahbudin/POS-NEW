<?php

namespace App\Domain\Ordering\Actions;

use App\Domain\Floor\Actions\SyncTableStatusAction;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/** Gabung meja: all live items of the source order move to the target; source order is closed as merged. */
class MergeOrdersAction
{
    public function __construct(private MoveItemsAction $move, private SyncTableStatusAction $syncTable) {}

    public function handle(Order $source, Order $target, User $actor): Order
    {
        return DB::transaction(function () use ($source, $target, $actor) {
            $ids = array_map('intval', $source->items()->whereIn('status', ['new', 'preparing', 'ready'])->pluck('id')->all());
            $this->move->handle($ids, $target, $actor);

            $source = Order::query()->lockForUpdate()->findOrFail($source->id);
            $source->update([
                'status' => $source->status->transitionTo(OrderStatus::Cancelled),
                'cancel_reason' => "Digabung ke {$target->number}", 'cancelled_by' => $actor->id, 'closed_at' => now(),
            ]);
            $source->rounds()->where('status', 'pending')->update(['status' => 'rejected', 'reject_reason' => 'Meja digabung']);
            $this->syncTable->handle($source->table_id);
            Audit::log('tables.merged', $target, ['from' => $source->number, 'into' => $target->number], $actor);

            return $target->refresh();
        });
    }
}
