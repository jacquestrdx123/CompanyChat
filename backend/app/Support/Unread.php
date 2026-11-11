<?php

namespace App\Support;

use App\Enums\ChannelKind;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Unread counts from per-user read markers (Supabase's get_unread_counts / get_unread_mention_counts).
 * A project the user has never had a marker for starts as "read now", so joining an old project does
 * not show its whole history as unread. A DM with no marker counts from its start.
 */
class Unread
{
    public static function markRead(User $user, string $channelId): void
    {
        DB::table('channel_reads')->upsert(
            [['user_id' => $user->id, 'channel_id' => $channelId, 'last_read_at' => now()->format('Y-m-d H:i:s.u')]],
            ['user_id', 'channel_id'],
            ['last_read_at'],
        );
    }

    /** @return array{unread: array<string, int>, mentions: array<string, int>} only channels with a count > 0 */
    public static function counts(User $user): array
    {
        $accessible = Channel::query()->accessibleTo($user)->select('channels.id');

        $missing = Channel::query()->accessibleTo($user)
            ->where('kind', ChannelKind::Project->value)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('channel_reads')
                ->whereColumn('channel_reads.channel_id', 'channels.id')->where('channel_reads.user_id', $user->id))
            ->pluck('channels.id');
        if ($missing->isNotEmpty()) {
            $now = now()->format('Y-m-d H:i:s.u');
            DB::table('channel_reads')->insertOrIgnore($missing->map(fn ($id) => [
                'user_id' => $user->id, 'channel_id' => $id, 'last_read_at' => $now,
            ])->all());
        }

        $unread = DB::table('messages as m')
            ->join('channels as c', 'c.id', '=', 'm.channel_id')
            ->leftJoin('channel_reads as r', fn ($j) => $j->on('r.channel_id', '=', 'm.channel_id')->where('r.user_id', '=', $user->id))
            ->whereIn('m.channel_id', $accessible)
            ->where(fn ($q) => $q->whereNull('m.user_id')->orWhere('m.user_id', '!=', $user->id))
            ->whereRaw('m.created_at > coalesce(r.last_read_at, c.created_at)')
            ->groupBy('m.channel_id')
            ->selectRaw('m.channel_id, count(*) as n')
            ->pluck('n', 'm.channel_id');

        $mentions = DB::table('message_mentions as mm')
            ->join('messages as m', 'm.id', '=', 'mm.message_id')
            ->join('channels as c', 'c.id', '=', 'mm.channel_id')
            ->leftJoin('channel_reads as r', fn ($j) => $j->on('r.channel_id', '=', 'mm.channel_id')->where('r.user_id', '=', $user->id))
            ->where('mm.user_id', $user->id)
            ->whereIn('mm.channel_id', Channel::query()->accessibleTo($user)->select('channels.id'))
            ->whereRaw('m.created_at > coalesce(r.last_read_at, c.created_at)')
            ->groupBy('mm.channel_id')
            ->selectRaw('mm.channel_id, count(*) as n')
            ->pluck('n', 'mm.channel_id');

        return [
            'unread' => $unread->map(fn ($n) => (int) $n)->all(),
            'mentions' => $mentions->map(fn ($n) => (int) $n)->all(),
        ];
    }
}
