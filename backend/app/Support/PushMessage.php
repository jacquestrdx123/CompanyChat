<?php

namespace App\Support;

/**
 * Builds the text of a push notification. Mirrors supabase/functions/_shared/push.ts.
 */
class PushMessage
{
    private const TOKEN = '/@\[([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}|channel)\]/i';

    public static function truncate(string $text, int $max = 140): string
    {
        $flat = trim(preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($flat) > $max ? mb_substr($flat, 0, $max - 1).'…' : $flat;
    }

    /** "@[uuid]" tokens become "@Name"; an empty (attachment-only) message says so. */
    public static function readableBody(string $body, callable $nameOf): string
    {
        $text = preg_replace_callback(self::TOKEN, fn ($m) => strtolower($m[1]) === 'channel' ? '@channel' : '@'.$nameOf(strtolower($m[1])), $body);

        return self::truncate($text) ?: 'Sent an attachment';
    }

    /**
     * @param  'dm'|'mention'|'watch'  $kind
     * @return array{title: string, body: string, tag: string, url: string}
     */
    public static function build(string $kind, string $channelId, string $channelKind, ?string $channelName, string $senderName, string $body, bool $showPreview, callable $nameOf): array
    {
        $text = self::readableBody($body, $nameOf);
        $url = "/c/{$channelId}";
        $tag = $channelId; // a newer message in the same channel replaces the older notification

        if ($channelKind === 'dm') {
            return ['title' => $senderName, 'body' => $showPreview ? $text : 'New message', 'tag' => $tag, 'url' => $url];
        }
        $title = '# '.($channelName ?? 'channel');
        if (! $showPreview) {
            return ['title' => $title, 'body' => $kind === 'mention' ? "{$senderName} mentioned you" : 'New message', 'tag' => $tag, 'url' => $url];
        }

        return ['title' => $title, 'body' => "{$senderName}: {$text}", 'tag' => $tag, 'url' => $url];
    }
}
