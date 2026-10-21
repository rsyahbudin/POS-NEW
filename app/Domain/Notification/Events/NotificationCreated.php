<?php

namespace App\Domain\Notification\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Only a nudge: screens refetch their own feed, so nothing about the notification travels over the socket. */
class NotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $outletId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("outlet.{$this->outletId}.notify")];
    }

    public function broadcastAs(): string
    {
        return 'NotificationCreated';
    }
}
