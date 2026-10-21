<?php

namespace App\Domain\Ordering\Actions;

use App\Domain\Ordering\Enums\ItemStatus;
use App\Domain\Ordering\Events\OrderItemStatusChanged;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\Models\OrderItem;
use App\Models\User;
use App\Support\Audit;
use App\Support\Realtime;
use Illuminate\Support\Facades\DB;

/** Kitchen taps an item forward: New -> Preparing -> Ready. */
class UpdateItemStatusAction
{
    public function handle(OrderItem $item, User $user): OrderItem
    {
        return DB::transaction(function () use ($item, $user) {
            $item = OrderItem::query()->lockForUpdate()->with('order.table')->findOrFail($item->id);
            $next = $item->status->next();
            if ($item->round->status->value !== 'confirmed') {
                throw new OrderException('Pesanan belum dikonfirmasi.');
            }

            $item->update([
                'status' => $next,
                'preparing_at' => $next === ItemStatus::Preparing ? now() : $item->preparing_at,
                'ready_at' => $next === ItemStatus::Ready ? now() : $item->ready_at,
                'preparing_by' => $next === ItemStatus::Preparing ? $user->id : $item->preparing_by,
                'ready_by' => $next === ItemStatus::Ready ? $user->id : $item->ready_by,
            ]);
            Audit::log('item.status', $item, ['order' => $item->order->number, 'item' => $item->name_snapshot, 'status' => $next->value], $user);

            Realtime::dispatch(new OrderItemStatusChanged(
                $item->order->outlet_id, $item->order_id, $item->id, $item->station_id,
                $next->value, $item->order->table?->public_id,
                $next === ItemStatus::Ready ? "{$item->name_snapshot} siap" : '',
            ));

            return $item;
        });
    }
}
