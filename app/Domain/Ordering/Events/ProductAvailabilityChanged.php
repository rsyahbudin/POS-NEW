<?php

namespace App\Domain\Ordering\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class ProductAvailabilityChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $outletId, public int $productId, public bool $isAvailable) {}

    /** Public channel: the QR menu has no login. Payload carries no sensitive data. */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("outlet.{$this->outletId}.orders"), new Channel("outlet.{$this->outletId}.menu")];
    }

    public function broadcastAs(): string
    {
        return 'ProductAvailabilityChanged';
    }
}
