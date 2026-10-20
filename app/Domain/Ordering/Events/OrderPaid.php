<?php

namespace App\Domain\Ordering\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class OrderPaid implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $outletId, public int $orderId, public ?string $tablePublicId) {}

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel("outlet.{$this->outletId}.orders")];
        if ($this->tablePublicId) {
            $channels[] = new Channel("table.{$this->tablePublicId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'OrderPaid';
    }
}
