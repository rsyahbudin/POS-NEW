<?php

namespace App\Domain\Floor\Actions;

use App\Domain\Floor\Enums\TableStatus;
use App\Domain\Floor\Models\Table;
use App\Domain\Ordering\Models\Order;

/** Table status always follows the table's active order. */
class SyncTableStatusAction
{
    public function handle(?int $tableId): void
    {
        if ($tableId === null) {
            return;
        }

        $order = Order::query()
            ->where('table_id', $tableId)
            ->whereIn('status', ['open', 'partially_paid'])
            ->whereHas('items', fn ($q) => $q->whereIn('status', ['new', 'preparing', 'ready']))
            ->latest('id')
            ->first();

        $status = match (true) {
            $order === null => TableStatus::Empty,
            $order->bill_requested_at !== null || $order->status->value === 'partially_paid' => TableStatus::AwaitingPayment,
            default => TableStatus::Occupied,
        };

        Table::query()->whereKey($tableId)->update(['status' => $status->value]);
    }
}
