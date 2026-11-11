<?php

namespace App\Support;

use App\Enums\UserStatus;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who should get a push for a new message (Supabase's push_targets). Same rules as in-app notifications:
 * DMs always, @mentions (including @channel) always, other project messages only for people watching
 * the channel. Never the author, deactivated users, or people on Do not disturb.
 */
class PushTargets
{
    /** @return array<string, 'dm'|'mention'|'watch'> user id => why */
    public static function for(Message $message): array
    {
        $channel = $message->channel;
        $candidates = [];

        foreach (DB::table('message_mentions')->where('message_id', $message->id)->pluck('user_id') as $uid) {
            $candidates[$uid] ??= 'mention';
        }
        if ($channel->isDm()) {
            foreach ($channel->members()->pluck('users.id') as $uid) {
                $candidates[$uid] ??= 'dm';
            }
        }
        foreach (DB::table('channel_watches')->where('channel_id', $channel->id)->pluck('user_id') as $uid) {
            $candidates[$uid] ??= 'watch';
        }
        unset($candidates[$message->user_id]);
        if (! $candidates) {
            return [];
        }

        $users = User::query()->whereIn('id', array_keys($candidates))
            ->where('is_active', true)->where('status', '!=', UserStatus::Dnd->value)->get()->keyBy('id');

        $out = [];
        foreach ($candidates as $uid => $kind) {
            $user = $users->get($uid);
            if (! $user) {
                continue;
            }
            // watchers (and DM members of an archived channel) must still be able to open the channel
            if ($kind !== 'mention' && ! Access::canAccessChannel($user, $channel)) {
                continue;
            }
            $out[$uid] = $kind;
        }

        return $out;
    }
}
