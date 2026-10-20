<?php

namespace App\Domain\Ordering\Actions;

use App\Domain\Ordering\Enums\CompType;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Payment\SplitGuard;
use App\Domain\Pricing\Actions\RecalculateOrderAction;
use App\Models\User;
use App\Support\ApprovalGate;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/** Complimentary / staff meal: excluded from revenue, reported separately, not journaled until COGS exists (v2.0). */
class MarkCompAction
{
    public function __construct(
        private ApprovalGate $gate,
        private RecalculateOrderAction $recalculate,
        private SplitGuard $split,
    ) {}

    public function handle(OrderItem $item, User $actor, CompType $type, string $reason, ?string $pin = null): OrderItem
    {
        if (trim($reason) === '') {
            throw new OrderException('Alasan wajib diisi.');
        }
        $approver = $this->gate->resolve($actor, 'void', $pin);

        return DB::transaction(function () use ($item, $actor, $approver, $type, $reason) {
            $order = Order::query()->lockForUpdate()->findOrFail($item->order_id);
            $item = OrderItem::query()->lockForUpdate()->findOrFail($item->id);
            if (! $order->status->isEditable() || ! $item->status->isLive()) {
                throw new OrderException('Item tidak bisa ditandai.');
            }
            $this->split->prepare($order, $item);

            $item->update(['comp_type' => $type, 'comp_reason' => $reason, 'comp_approved_by' => $approver->id]);
            $this->recalculate->handle($order);
            Audit::log('item.comp', $item, ['order' => $order->number, 'type' => $type->value, 'reason' => $reason, 'approved_by' => $approver->id], $actor);

            return $item;
        });
    }
}
