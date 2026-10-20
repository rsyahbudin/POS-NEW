<?php

namespace App\Domain\Ordering\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class OrderItemStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $outletId, public int $orderId, public int $itemId, public ?int $stationId, public string $status, public ?string $tablePublicId, public string $label = '') {}

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel("outlet.{$this->outletId}.orders")];
        if ($this->stationId) {
            $channels[] = new PrivateChannel("outlet.{$this->outletId}.kitchen.{$this->stationId}");
        }
        if ($this->tablePublicId) {
            $channels[] = new Channel("table.{$this->tablePublicId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'OrderItemStatusChanged';
    }
}
