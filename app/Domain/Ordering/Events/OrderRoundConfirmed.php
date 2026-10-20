<?php

namespace App\Domain\Ordering\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class OrderRoundConfirmed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @param  array<int, int>  $stationIds */
    public function __construct(public int $outletId, public int $orderId, public int $roundId, public array $stationIds, public ?string $tablePublicId, public string $status = 'confirmed') {}

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel("outlet.{$this->outletId}.orders")];
        foreach ($this->stationIds as $id) {
            $channels[] = new PrivateChannel("outlet.{$this->outletId}.kitchen.{$id}");
        }
        if ($this->tablePublicId) {
            $channels[] = new Channel("table.{$this->tablePublicId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'OrderRoundConfirmed';
    }
}
