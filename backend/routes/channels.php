<?php

use App\Models\User;
use App\Support\Access;
use Illuminate\Support\Facades\Broadcast;

/*
| Reverb channels (replaces Supabase Realtime). Authorised at POST /api/broadcasting/auth with the
| same bearer token as the REST API.
|
|   presence-office         everyone online; profile.updated, directory.changed
|   private-channel.{id}    message.created, message.updated, message.deleted
|   private-user.{id}       mention.created (only for you)
*/

Broadcast::channel('office', function (User $user) {
    if (! $user->is_active) {
        return false;
    }

    return ['id' => $user->id, 'display_name' => $user->display_name];
});

Broadcast::channel('channel.{channelId}', function (User $user, string $channelId) {
    return Access::canAccessChannel($user, $channelId);
});

Broadcast::channel('user.{id}', function (User $user, string $id) {
    return $user->is_active && $user->id === $id;
});
