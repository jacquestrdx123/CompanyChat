<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageUpdated;
use App\Http\Controllers\Controller;
use App\Models\MessageAttachment;
use App\Support\Access;
use App\Support\Attachments;
use App\Support\Realtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * Signed URLs for several attachments at once (replaces storage.createSignedUrls).
     * Body: { ids: [...], download?: bool }. Images default to inline, other files to download.
     * Returns { urls: { <attachment id>: <url> }, expires_in: seconds }. Unknown or inaccessible ids are left out.
     */
    public function urls(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:200'],
            'ids.*' => ['uuid'],
            'download' => ['nullable', 'boolean'],
        ]);
        $user = $request->user();

        $attachments = MessageAttachment::query()->whereIn('id', $data['ids'])->whereNull('deleted_at')->get();
        $allowed = $attachments->pluck('channel_id')->unique()->filter(fn ($cid) => Access::canAccessChannel($user, $cid))->flip();

        $urls = [];
        foreach ($attachments as $a) {
            if ($allowed->has($a->channel_id)) {
                $urls[$a->id] = Attachments::signedUrl($a, $data['download'] ?? null);
            }
        }

        return response()->json(['urls' => $urls, 'expires_in' => Attachments::URL_TTL_SECONDS]);
    }

    /** Serves the file behind a signed URL (no bearer token needed, so <img src> works). */
    public function file(Request $request, MessageAttachment $attachment): StreamedResponse
    {
        abort_if($attachment->deleted_at !== null, 404);
        $disk = Attachments::disk();
        abort_unless($disk->exists($attachment->path), 404);

        $headers = [
            'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ];
        // Only known-safe images are ever shown inline; everything else is forced to download.
        $inline = ! $request->boolean('download') && Attachments::isInlineImage($attachment->mime_type);

        return $inline
            ? $disk->response($attachment->path, $attachment->file_name, $headers)
            : $disk->download($attachment->path, $attachment->file_name, $headers);
    }

    /** Delete one attachment but keep the message: the file goes, the row is flagged. */
    public function destroy(Request $request, MessageAttachment $attachment): JsonResponse
    {
        $user = $request->user();
        $message = $attachment->message;
        $channel = $attachment->channel;
        abort_unless(Access::canSeeChannel($user, $channel), 404);
        abort_unless($message->user_id === $user->id || Access::canManageChannel($user, $channel), 403, 'You can only delete your own files.');

        if ($attachment->deleted_at === null) {
            Attachments::disk()->delete($attachment->path);
            $attachment->deleted_at = now();
            $attachment->save();
            Realtime::send(new MessageUpdated($message));
        }

        return response()->json(['ok' => true, 'deleted_at' => $attachment->deleted_at->toISOString()]);
    }
}
