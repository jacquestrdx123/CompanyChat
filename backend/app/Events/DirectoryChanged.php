<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The clients/channels people can see may have changed (new project or DM, membership change,
 * rename, archive/restore). Carries no data, so nothing leaks: clients refetch GET /api/clients and
 * /api/channels, which only return what each person may see.
 */
class DirectoryChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function broadcastOn(): array
    {
        return [new PresenceChannel('office')];
    }

    public function broadcastAs(): string
    {
        return 'directory.changed';
    }

    public function broadcastWith(): array
    {
        return [];
    }
}
