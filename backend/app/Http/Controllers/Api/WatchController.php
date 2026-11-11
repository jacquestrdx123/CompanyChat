<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Watching a project = notifications for every message in it. Without a watch, projects only notify
 * on @mentions; DMs always notify. Watches are private to each person.
 */
class WatchController extends Controller
{
    /** The ids of the channels you watch. */
    public function index(Request $request): JsonResponse
    {
        return response()->json(DB::table('channel_watches')->where('user_id', $request->user()->id)->pluck('channel_id'));
    }

    public function store(Request $request, Channel $channel): JsonResponse
    {
        abort_unless(Access::canAccessChannel($request->user(), $channel), 404);
        DB::table('channel_watches')->insertOrIgnore(['user_id' => $request->user()->id, 'channel_id' => $channel->id, 'created_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, Channel $channel): JsonResponse
    {
        DB::table('channel_watches')->where('user_id', $request->user()->id)->where('channel_id', $channel->id)->delete();

        return response()->json(['ok' => true]);
    }
}
