<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageCreated;
use App\Events\MessageDeleted;
use App\Events\MessageUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Jobs\SendMessagePush;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Support\Access;
use App\Support\Attachments;
use App\Support\Mentions;
use App\Support\Realtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Throwable;

class MessageController extends Controller
{
    public const PAGE_SIZE = 100;

    /**
     * Messages in a channel, oldest first, with attachments.
     *   (default)         the latest page
     *   ?before=<id>      the page before that message (older history)
     *   ?from=<id>        that message and up to 500 after it (jump from search)
     *   ?limit=<n>        page size, 1-500
     */
    public function index(Request $request, Channel $channel): AnonymousResourceCollection
    {
        abort_unless(Access::canAccessChannel($request->user(), $channel), 404);
        $data = $request->validate([
            'before' => ['nullable', 'uuid'],
            'from' => ['nullable', 'uuid'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        $query = $channel->messages()->with('attachments');

        if (! empty($data['from'])) {
            $anchor = $channel->messages()->whereKey($data['from'])->firstOrFail();
            $messages = $query->where('created_at', '>=', $anchor->created_at)
                ->orderBy('created_at')->orderBy('id')->limit($data['limit'] ?? 500)->get();

            return MessageResource::collection($messages);
        }

        if (! empty($data['before'])) {
            $anchor = $channel->messages()->whereKey($data['before'])->firstOrFail();
            $query->where('created_at', '<', $anchor->created_at);
        }
        $messages = $query->orderByDesc('created_at')->orderByDesc('id')
            ->limit($data['limit'] ?? self::PAGE_SIZE)->get()->reverse()->values();

        return MessageResource::collection($messages);
    }

    /**
     * Post a message, with optional files (multipart: body, files[]). The message and its files are saved
     * together, so a failed upload never leaves a half-sent message behind. Send the X-Socket-ID header so
     * your own socket does not also receive the broadcast.
     */
    public function store(Request $request, Channel $channel): JsonResponse
    {
        $user = $request->user();
        abort_unless(Access::canAccessChannel($user, $channel), 404);
        abort_unless(Access::canPostInChannel($user, $channel), 403, 'You can only read in this channel.');

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:'.Message::MAX_BODY],
            'files' => ['nullable', 'array', 'max:'.Attachments::MAX_FILES_PER_MESSAGE],
            'files.*' => ['file', 'max:'.(MessageAttachment::MAX_BYTES / 1024)],
        ]);
        $body = trim($data['body'] ?? '');
        $files = $request->file('files', []);
        abort_if($body === '' && ! $files, 422, 'Write a message or attach a file.');
        foreach ($files as $file) {
            abort_if($file->getSize() === 0, 422, "{$file->getClientOriginalName()} is empty.");
        }

        $disk = Attachments::disk();
        $written = [];
        $mentioned = [];
        try {
            $message = DB::transaction(function () use ($channel, $user, $body, $files, $disk, &$written, &$mentioned) {
                $message = Message::create(['channel_id' => $channel->id, 'user_id' => $user->id, 'body' => $body]);
                foreach (array_values($files) as $i => $file) {
                    $name = $file->getClientOriginalName();
                    $path = "{$channel->id}/{$message->id}/{$i}_".Attachments::safeFileName($name);
                    $disk->putFileAs(dirname($path), $file, basename($path));
                    $written[] = $path;
                    $message->attachments()->create([
                        'channel_id' => $channel->id,
                        'path' => $path,
                        'file_name' => mb_substr($name, 0, 255),
                        'mime_type' => $file->getClientMimeType() ?: null,
                        'size_bytes' => $file->getSize(),
                    ]);
                }
                $mentioned = Mentions::sync($message);

                return $message;
            });
        } catch (Throwable $e) {
            if ($written) {
                $disk->delete($written);
            }
            throw $e;
        }

        $message->load('attachments');
        Realtime::send(new MessageCreated($message));
        Mentions::broadcast($message, $mentioned);
        SendMessagePush::dispatch($message->id)->afterCommit();

        return (new MessageResource($message))->response()->setStatusCode(201);
    }

    /** Authors edit their own messages (and only where they may still post). */
    public function update(Request $request, Message $message): MessageResource
    {
        $user = $request->user();
        abort_unless(Access::canAccessChannel($user, $message->channel_id), 404);
        abort_unless($message->user_id === $user->id && Access::canPostInChannel($user, $message->channel), 403, 'You can only edit your own messages.');

        $data = $request->validate(['body' => ['required', 'string', 'max:'.Message::MAX_BODY]]);
        $body = trim($data['body']);
        abort_if($body === '' && ! $message->attachments()->exists(), 422, 'A message cannot be empty.');

        if ($body !== $message->body) {
            $mentioned = DB::transaction(function () use ($message, $body) {
                $message->body = $body;
                $message->edited_at = now();
                $message->save();

                return Mentions::sync($message);
            });
            Realtime::send(new MessageUpdated($message));
            Mentions::broadcast($message, $mentioned);
        }

        return new MessageResource($message->load('attachments'));
    }

    /** Authors delete their own; admins can moderate project messages. Files go with it. */
    public function destroy(Request $request, Message $message): JsonResponse
    {
        $user = $request->user();
        $channel = $message->channel;
        abort_unless(Access::canSeeChannel($user, $channel), 404);
        abort_unless($message->user_id === $user->id || Access::canManageChannel($user, $channel), 403, 'You can only delete your own messages.');

        $paths = $message->attachments()->whereNull('deleted_at')->pluck('path')->all();
        $message->delete();
        if ($paths) {
            rescue(fn () => Attachments::disk()->delete($paths), report: true); // best effort
        }
        Realtime::send(new MessageDeleted($message->id, $channel->id));

        return response()->json(['ok' => true]);
    }
}
