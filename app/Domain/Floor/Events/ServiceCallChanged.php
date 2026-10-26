<?php

namespace App\Domain\Floor\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class ServiceCallChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $outletId, public int $callId, public string $tableName, public string $status, public string $reason) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("outlet.{$this->outletId}.calls")];
    }

    public function broadcastAs(): string
    {
        return 'ServiceCallChanged';
    }
}
