<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Word-prefix search over the messages you can read, newest first. */
class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($data['q'] ?? '')) ?: []));
        if (! $words) {
            return response()->json([]);
        }

        $query = Message::query()
            ->whereIn('channel_id', Channel::query()->accessibleTo($request->user())->select('channels.id'))
            ->select(['id', 'channel_id', 'user_id', 'body', 'created_at']);

        if (DB::getDriverName() === 'pgsql') {
            // Uses the GIN index on body_tsv; every word must match as a prefix.
            $tsquery = implode(' & ', array_map(fn ($w) => $w.':*', $words));
            $query->whereRaw("body_tsv @@ to_tsquery('simple', ?)", [$tsquery]);
        } else {
            foreach ($words as $w) {
                $query->whereRaw('lower(body) like ?', ['%'.addcslashes($w, '%_\\').'%']);
            }
        }

        $results = $query->orderByDesc('created_at')->limit($data['limit'] ?? 30)->get();

        return response()->json($results->map(fn (Message $m) => [
            'id' => $m->id,
            'channel_id' => $m->channel_id,
            'user_id' => $m->user_id,
            'body' => $m->body,
            'created_at' => $m->created_at->copy()->utc()->format('Y-m-d\TH:i:s.u\Z'),
        ]));
    }
}
