<?php

namespace App\Domain\Pricing\Actions;

use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Payment\SplitGuard;
use App\Domain\Pricing\Models\Discount;
use App\Domain\Settings\SettingsRepository;
use App\Models\User;
use App\Support\ApprovalGate;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Discount per item or per order. Percent is stored in basis points (1000 = 10%).
 * Above the cashier limit a manager PIN is required.
 */
class ApplyDiscountAction
{
    public function __construct(
        private ApprovalGate $gate,
        private SettingsRepository $settings,
        private RecalculateOrderAction $recalculate,
        private SplitGuard $split,
    ) {}

    public function handle(Order $order, User $actor, string $type, int $value, string $reason, ?OrderItem $item = null, ?string $pin = null): Discount
    {
        if (! in_array($type, ['percent', 'fixed'], true)) {
            throw new OrderException('Tipe diskon tidak valid.');
        }
        if ($value <= 0) {
            throw new OrderException('Nilai diskon harus lebih dari 0.');
        }
        if ($type === 'percent' && $value > 10000) {
            throw new OrderException('Diskon maksimal 100%.');
        }
        if (trim($reason) === '') {
            throw new OrderException('Alasan diskon wajib diisi.');
        }

        return DB::transaction(function () use ($order, $actor, $type, $value, $reason, $item, $pin) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (! $order->status->isEditable()) {
                throw new OrderException('Order sudah dikunci.');
            }
            if ($item && $item->order_id !== $order->id) {
                throw new OrderException('Item bukan bagian dari order ini.');
            }

            $base = $item ? $item->line_total : (int) $order->items()->whereIn('status', ['new', 'preparing', 'ready'])->whereNull('comp_type')->sum('line_total');
            $percentBp = $type === 'percent' ? $value : ($base > 0 ? intdiv($value * 10000, $base) : 10000);
            $limitBp = (int) $this->settings->get('discount.cashier_limit_percent', 10) * 100;

            $approver = $percentBp <= $limitBp && $actor->can('discount-within-limit')
                ? $actor
                : $this->gate->resolve($actor, 'discount-over-limit', $pin);

            $this->split->prepare($order, $item);

            $discount = Discount::create([
                'order_id' => $order->id,
                'order_item_id' => $item?->id,
                'type' => $type,
                'value' => $value,
                'reason' => $reason,
                'created_by' => $actor->id,
                'approved_by' => $approver->id,
            ]);
            $this->recalculate->handle($order);

            Audit::log('discount.applied', $order, [
                'order' => $order->number, 'type' => $type, 'value' => $value,
                'amount' => $discount->refresh()->amount, 'reason' => $reason, 'approved_by' => $approver->id,
            ], $actor);

            return $discount;
        });
    }

    public function remove(Discount $discount, User $actor): void
    {
        DB::transaction(function () use ($discount, $actor) {
            $order = Order::query()->lockForUpdate()->findOrFail($discount->order_id);
            if (! $order->status->isEditable()) {
                throw new OrderException('Order sudah dikunci.');
            }
            $this->split->prepare($order);
            $discount->update(['removed_at' => now()]);
            $this->recalculate->handle($order);
            Audit::log('discount.removed', $order, ['order' => $order->number, 'discount' => $discount->id], $actor);
        });
    }
}
