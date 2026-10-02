<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final class DataChanged implements ShouldBroadcastNow
{
    public function broadcastOn(): array
    {
        return [new PrivateChannel('lager.updates')];
    }

    public function broadcastAs(): string
    {
        return 'data.changed';
    }

    public function broadcastWith(): array
    {
        return [];
    }
}
