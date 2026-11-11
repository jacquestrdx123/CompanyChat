<?php

namespace App\Support;

use App\Models\Channel;
use App\Models\Client;
use App\Models\User;

/**
 * Who may see and do what. Replaces the Supabase RLS helper functions
 * (can_user_access_channel, can_post_in_channel, can_manage_channel, ...).
 */
class Access
{
    public static function canAccessChannel(User $user, Channel|string $channel): bool
    {
        $id = $channel instanceof Channel ? $channel->id : $channel;

        return Channel::query()->whereKey($id)->accessibleTo($user)->exists();
    }

    /** Viewers can only write in DMs. */
    public static function canPostInChannel(User $user, Channel $channel): bool
    {
        return self::canAccessChannel($user, $channel) && (! $user->isViewer() || $channel->isDm());
    }

    /** Only admins manage projects; DMs are never managed. */
    public static function canManageChannel(User $user, Channel $channel): bool
    {
        return $user->is_active && $user->isAdmin() && $channel->isProject();
    }

    public static function canManageClient(User $user): bool
    {
        return $user->is_active && $user->isAdmin();
    }

    /** Admins see every client (archived too, to restore them); members see their active clients. */
    public static function canSeeClient(User $user, Client $client): bool
    {
        if (self::canManageClient($user)) {
            return true;
        }

        return $user->is_active && ! $client->isArchived()
            && $client->members()->whereKey($user->id)->exists();
    }

    /**
     * Admins can also see archived projects (to restore them), not just the ones they can open.
     */
    public static function canSeeChannel(User $user, Channel $channel): bool
    {
        return self::canAccessChannel($user, $channel)
            || ($channel->isProject() && self::canManageClient($user));
    }
}
