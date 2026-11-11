<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Someone @mentioned this person (or @channel). Heard only on their own private-user.{id}. */
class UserMentioned implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public string $userId, public string $messageId, public string $channelId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'mention.created';
    }

    public function broadcastWith(): array
    {
        return ['message_id' => $this->messageId, 'channel_id' => $this->channelId];
    }
}
