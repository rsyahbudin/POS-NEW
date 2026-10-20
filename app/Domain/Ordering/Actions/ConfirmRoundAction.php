<?php

namespace App\Domain\Ordering\Actions;

use App\Domain\Floor\Actions\SyncTableStatusAction;
use App\Domain\Ordering\Enums\RoundStatus;
use App\Domain\Ordering\Events\OrderRoundConfirmed;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderRound;
use App\Domain\Ordering\StockService;
use App\Domain\Pricing\Actions\ApplyPromotionsAction;
use App\Domain\Pricing\Actions\RecalculateOrderAction;
use App\Models\User;
use App\Support\Audit;
use App\Support\Realtime;
use Illuminate\Support\Facades\DB;

class ConfirmRoundAction
{
    public function __construct(
        private StockService $stock,
        private RecalculateOrderAction $recalculate,
        private ApplyPromotionsAction $promotions,
        private SyncTableStatusAction $syncTable,
    ) {}

    /**
     * @param  array<int, int>  $qtyChanges  order_item_id => new qty (0 removes the item)
     */
    public function handle(OrderRound $round, User $cashier, array $qtyChanges = []): OrderRound
    {
        return DB::transaction(function () use ($round, $cashier, $qtyChanges) {
            $order = Order::query()->lockForUpdate()->findOrFail($round->order_id);
            $round = OrderRound::query()->lockForUpdate()->findOrFail($round->id);

            if ($round->status !== RoundStatus::Pending) {
                throw new OrderException('Pesanan ini sudah diproses.');
            }
            if (! $order->status->isEditable()) {
                throw new OrderException('Order sudah dikunci.');
            }

            foreach ($round->items as $item) {
                if (! array_key_exists($item->id, $qtyChanges)) {
                    continue;
                }
                $qty = (int) $qtyChanges[$item->id];
                if ($qty <= 0) {
                    $item->update(['status' => 'rejected', 'void_reason' => 'Dihapus kasir sebelum konfirmasi']);
                } elseif ($qty !== $item->qty) {
                    $item->update(['qty' => $qty, 'line_total' => $item->unit_price * $qty]);
                }
            }

            $live = $round->items()->where('status', 'pending')->get();
            if ($live->isEmpty()) {
                throw new OrderException('Semua item dihapus. Gunakan Tolak untuk membatalkan pesanan.');
            }
            foreach ($live as $item) {
                $this->stock->reserve($item);
                $item->update(['status' => 'new']);
            }

            $round->update(['status' => RoundStatus::Confirmed, 'confirmed_by' => $cashier->id, 'confirmed_at' => now()]);

            $this->promotions->handle($order);
            $this->recalculate->handle($order);
            $this->syncTable->handle($order->table_id);
            Audit::log('qr.confirmed', $round, ['order' => $order->number], $cashier);

            Realtime::dispatch(new OrderRoundConfirmed(
                $order->outlet_id, $order->id, $round->id,
                array_map('intval', $live->pluck('station_id')->filter()->unique()->values()->all()),
                $order->table?->public_id,
            ));

            return $round->refresh()->load('items');
        });
    }
}
