<?php

namespace App\Domain\Ordering\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class QrOrderSubmitted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $outletId, public int $orderId, public int $roundId, public string $customerName, public ?string $tableName, public bool $autoConfirmed = false) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("outlet.{$this->outletId}.orders")];
    }

    public function broadcastAs(): string
    {
        return 'QrOrderSubmitted';
    }
}
