<?php

namespace App\Support;

use App\Models\MessageAttachment;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class Attachments
{
    /** Signed URLs live as long as Supabase's did. */
    public const URL_TTL_SECONDS = 6 * 60 * 60;

    public static function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.attachments_disk'));
    }

    public const MAX_FILES_PER_MESSAGE = 5;

    /** Storage-safe file name: letters, digits, dot, dash, underscore (same as the app's utils/files.ts). */
    public static function safeFileName(string $name): string
    {
        $clean = preg_replace('/^\.+/', '', preg_replace('/[^\w.-]+/', '_', $name));

        return substr($clean ?: 'file', -100);
    }

    /** Browsers render these safely inline; everything else is served as a download. */
    public static function isInlineImage(?string $mime): bool
    {
        return in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true);
    }

    /** A short-lived URL anyone holding it can open (so it works in <img src> without a token). */
    public static function signedUrl(MessageAttachment $a, ?bool $download = null): string
    {
        $download ??= ! self::isInlineImage($a->mime_type);

        return URL::temporarySignedRoute(
            'attachments.file',
            now()->addSeconds(self::URL_TTL_SECONDS),
            ['attachment' => $a->id, 'download' => $download ? 1 : 0],
        );
    }
}
