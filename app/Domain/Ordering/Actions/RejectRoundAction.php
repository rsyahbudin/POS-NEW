<?php

namespace App\Domain\Ordering\Actions;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Enums\RoundStatus;
use App\Domain\Ordering\Events\OrderRoundConfirmed;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderRound;
use App\Models\User;
use App\Support\Audit;
use App\Support\Realtime;
use Illuminate\Support\Facades\DB;

class RejectRoundAction
{
    public function handle(OrderRound $round, User $cashier, string $reason): OrderRound
    {
        if (trim($reason) === '') {
            throw new OrderException('Alasan penolakan wajib diisi.');
        }

        return DB::transaction(function () use ($round, $cashier, $reason) {
            $order = Order::query()->lockForUpdate()->findOrFail($round->order_id);
            $round = OrderRound::query()->lockForUpdate()->findOrFail($round->id);
            if ($round->status !== RoundStatus::Pending) {
                throw new OrderException('Pesanan ini sudah diproses.');
            }

            $round->update(['status' => RoundStatus::Rejected, 'reject_reason' => $reason, 'confirmed_by' => $cashier->id, 'confirmed_at' => now()]);
            $round->items()->update(['status' => 'rejected']);

            // An order that only ever held this rejected round is cancelled, never deleted.
            $hasLive = $order->items()->whereIn('status', ['new', 'preparing', 'ready', 'pending'])->exists();
            if (! $hasLive && $order->status === OrderStatus::Open) {
                $order->update(['status' => $order->status->transitionTo(OrderStatus::Cancelled), 'cancel_reason' => 'Pesanan QR ditolak', 'cancelled_by' => $cashier->id, 'closed_at' => now()]);
            }

            Audit::log('qr.rejected', $round, ['reason' => $reason], $cashier);
            Realtime::dispatch(new OrderRoundConfirmed($order->outlet_id, $order->id, $round->id, [], $order->table?->public_id, 'rejected'));

            return $round;
        });
    }
}
