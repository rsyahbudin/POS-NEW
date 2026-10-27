<?php

namespace App\Domain\Payment\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** A guest paid by QRIS from their phone and the payment is booked: the cashier hears and sees it. */
class OnlinePaymentReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $outletId, public int $orderId, public ?string $tableName, public int $amount) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("outlet.{$this->outletId}.orders")];
    }

    public function broadcastAs(): string
    {
        return 'OnlinePaymentReceived';
    }
}
