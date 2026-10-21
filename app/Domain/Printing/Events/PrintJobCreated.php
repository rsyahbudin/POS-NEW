<?php

namespace App\Domain\Printing\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class PrintJobCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $outletId, public int $printerId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("outlet.{$this->outletId}.print")];
    }

    public function broadcastAs(): string
    {
        return 'PrintJobCreated';
    }
}
