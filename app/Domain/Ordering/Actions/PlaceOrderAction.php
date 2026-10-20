<?php

namespace App\Domain\Ordering\Actions;

use App\Domain\Floor\Actions\SyncTableStatusAction;
use App\Domain\Floor\Models\Table;
use App\Domain\Ordering\DailyCounter;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Enums\OrderType;
use App\Domain\Ordering\Enums\RoundSource;
use App\Domain\Ordering\Enums\RoundStatus;
use App\Domain\Ordering\Events\OrderRoundConfirmed;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\ItemBuilder;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderRound;
use App\Domain\Ordering\StockService;
use App\Domain\Payment\SplitGuard;
use App\Domain\Pricing\Actions\ApplyPromotionsAction;
use App\Domain\Pricing\Actions\RecalculateOrderAction;
use App\Domain\Settings\OutletContext;
use App\Models\User;
use App\Support\BusinessDate;
use App\Support\Realtime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cashier / waiter places a round. Adds to the table's open order when there is
 * one (each round = one kitchen ticket), otherwise opens a new order.
 */
class PlaceOrderAction
{
    public function __construct(
        private ItemBuilder $items,
        private StockService $stock,
        private DailyCounter $counter,
        private RecalculateOrderAction $recalculate,
        private ApplyPromotionsAction $promotions,
        private SyncTableStatusAction $syncTable,
        private OutletContext $outlet,
        private SplitGuard $split,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $user, array $data): Order
    {
        $outletId = $this->outlet->id();
        $type = OrderType::from((string) $data['type']);

        if ($type === OrderType::DineIn && empty($data['table_id'])) {
            throw new OrderException('Pilih meja untuk dine-in.');
        }
        if ($type === OrderType::Online && blank($data['platform'] ?? null)) {
            throw new OrderException('Isi nama platform untuk order online.');
        }

        $items = is_array($data['items'] ?? null) ? array_values($data['items']) : [];
        $rows = $this->items->build($items, $outletId);

        return DB::transaction(function () use ($user, $data, $type, $rows, $outletId) {
            $order = $this->resolveOrder($user, $data, $type, $outletId);
            $this->split->forNewItems($order);

            $round = OrderRound::create([
                'order_id' => $order->id,
                'source' => RoundSource::Pos,
                'status' => RoundStatus::Confirmed,
                'confirmed_by' => $user->id,
                'confirmed_at' => now(),
                'created_by' => $user->id,
            ]);

            foreach ($rows as $row) {
                $item = $round->items()->create($row + ['order_id' => $order->id, 'status' => 'new']);
                $this->stock->reserve($item);
            }

            $this->promotions->handle($order);
            $this->recalculate->handle($order);
            $this->syncTable->handle($order->table_id);

            Realtime::dispatch(new OrderRoundConfirmed(
                $outletId, $order->id, $round->id,
                array_map('intval', collect($rows)->pluck('station_id')->filter()->unique()->values()->all()),
                $order->table?->public_id,
            ));

            return $order->refresh()->load(['items', 'table']);
        });
    }

    /** @param  array<string, mixed>  $data */
    private function resolveOrder(User $user, array $data, OrderType $type, int $outletId): Order
    {
        if (! empty($data['order_id'])) {
            $order = Order::query()->lockForUpdate()->whereKey((int) $data['order_id'])->firstOrFail();
            $this->assertEditable($order);

            return $order;
        }

        if ($type === OrderType::DineIn) {
            $table = Table::query()->lockForUpdate()->whereKey((int) $data['table_id'])->firstOrFail();
            $existing = Order::query()
                ->where('table_id', $table->id)
                ->whereIn('status', ['open', 'partially_paid'])
                ->lockForUpdate()->latest('id')->first();
            if ($existing) {
                return $existing;
            }
        }

        $date = BusinessDate::for();
        $number = sprintf('ORD-%s-%04d', CarbonImmutable::parse($date)->format('ymd'), $this->counter->next($outletId, $date, 'order'));
        $call = $type === OrderType::Takeaway ? sprintf('A-%03d', $this->counter->next($outletId, $date, 'call')) : null;

        return Order::create([
            'outlet_id' => $outletId,
            'number' => $number,
            'type' => $type,
            'platform' => $data['platform'] ?? null,
            'table_id' => $type === OrderType::DineIn ? $data['table_id'] : null,
            'status' => OrderStatus::Open,
            'customer_name' => $data['customer_name'] ?? null,
            'call_number' => $call,
            'business_date' => $date,
            'opened_by' => $user->id,
        ]);
    }

    private function assertEditable(Order $order): void
    {
        if (! $order->status->isEditable()) {
            throw new OrderException('Order sudah dikunci dan tidak bisa diubah.');
        }
    }
}
