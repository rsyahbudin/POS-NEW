<?php

namespace App\Domain\Ordering\Actions;

use App\Domain\Floor\Actions\SyncTableStatusAction;
use App\Domain\Ordering\Enums\RoundSource;
use App\Domain\Ordering\Enums\RoundStatus;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Payment\SplitGuard;
use App\Domain\Pricing\Actions\RecalculateOrderAction;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/** Moves unpaid items to another order (table transfer / merge). */
class MoveItemsAction
{
    public function __construct(
        private RecalculateOrderAction $recalculate,
        private SyncTableStatusAction $syncTable,
        private SplitGuard $split,
    ) {}

    /** @param  array<int, int>  $itemIds */
    public function handle(array $itemIds, Order $target, User $actor): Order
    {
        return DB::transaction(function () use ($itemIds, $target, $actor) {
            $target = Order::query()->lockForUpdate()->findOrFail($target->id);
            if (! $target->status->isEditable()) {
                throw new OrderException('Order tujuan sudah dikunci.');
            }

            $items = OrderItem::query()->whereIn('id', $itemIds)->lockForUpdate()->get();
            if ($items->isEmpty()) {
                throw new OrderException('Tidak ada item yang dipilih.');
            }

            $sources = Order::query()->whereIn('id', $items->pluck('order_id')->unique())->lockForUpdate()->get();
            foreach ($sources as $source) {
                if ($source->id === $target->id || ! $source->status->isEditable()) {
                    throw new OrderException('Order asal tidak valid atau sudah dikunci.');
                }
                $this->split->prepare($source);
            }
            $this->split->prepare($target);

            if ($items->contains(fn ($i) => ! $i->status->isLive())) {
                throw new OrderException('Hanya item aktif yang bisa dipindah.');
            }

            $round = $target->rounds()->create([
                'source' => RoundSource::Pos, 'status' => RoundStatus::Confirmed,
                'confirmed_by' => $actor->id, 'confirmed_at' => now(), 'created_by' => $actor->id,
            ]);
            foreach ($items as $item) {
                $item->update(['order_id' => $target->id, 'order_round_id' => $round->id]);
            }

            foreach ($sources as $source) {
                $this->recalculate->handle($source);
                $this->syncTable->handle($source->table_id);
            }
            $this->recalculate->handle($target);
            $this->syncTable->handle($target->table_id);

            Audit::log('items.moved', $target, ['to' => $target->number, 'items' => $items->pluck('id')->all()], $actor);

            return $target;
        });
    }
}
