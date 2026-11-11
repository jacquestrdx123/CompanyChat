<?php

namespace App\Support;

use App\Events\UserMentioned;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Keeps message_mentions in step with a message body (Supabase's sync_message_mentions trigger).
 * A mention is stored in the body as @[<user uuid>] so renames never break it; @[channel] mentions
 * everyone who can see the channel. Only real, active people who can see the channel, never the author,
 * at most 20 explicit mentions.
 */
class Mentions
{
    public const MAX_EXPLICIT = 20;

    private const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    /** @return list<string> lower-cased user ids, in order of appearance, without duplicates */
    public static function parseIds(string $body): array
    {
        preg_match_all('/@\[('.self::UUID.')\]/', $body, $m);

        return array_values(array_unique(array_map('strtolower', $m[1])));
    }

    public static function mentionsChannel(string $body): bool
    {
        return (bool) preg_match('/@\[channel\]/i', $body);
    }

    /**
     * Recompute the mentions of a message.
     *
     * @return list<string> the newly mentioned people (send them UserMentioned after the transaction)
     */
    public static function sync(Message $message): array
    {
        $channel = $message->channel;

        $explicit = User::query()
            ->whereIn('id', self::parseIds($message->body))
            ->where('is_active', true)
            ->when($message->user_id, fn ($q) => $q->where('id', '!=', $message->user_id))
            ->get()
            ->filter(fn (User $u) => Access::canAccessChannel($u, $channel))
            ->take(self::MAX_EXPLICIT)
            ->pluck('id');

        $ids = $explicit;
        if (self::mentionsChannel($message->body)) {
            $everyone = User::query()
                ->where('is_active', true)
                ->when($message->user_id, fn ($q) => $q->where('id', '!=', $message->user_id))
                ->get()
                ->filter(fn (User $u) => Access::canAccessChannel($u, $channel))
                ->pluck('id');
            $ids = $ids->merge($everyone);
        }
        $ids = $ids->unique()->values()->all();

        $existing = DB::table('message_mentions')->where('message_id', $message->id)->pluck('user_id')->all();

        DB::table('message_mentions')->where('message_id', $message->id)->whereNotIn('user_id', $ids)->delete();

        $new = array_values(array_diff($ids, $existing));
        if ($new) {
            DB::table('message_mentions')->insert(array_map(fn (string $uid) => [
                'message_id' => $message->id,
                'user_id' => $uid,
                'channel_id' => $message->channel_id,
                'created_at' => now(),
            ], $new));
        }

        return $new;
    }

    /** @param  list<string>  $userIds */
    public static function broadcast(Message $message, array $userIds): void
    {
        foreach ($userIds as $uid) {
            Realtime::send(new UserMentioned($uid, $message->id, $message->channel_id), toOthers: false);
        }
    }
}
