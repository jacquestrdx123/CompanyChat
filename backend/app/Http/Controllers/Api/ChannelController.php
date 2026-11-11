<?php

namespace App\Http\Controllers\Api;

use App\Enums\ChannelKind;
use App\Events\DirectoryChanged;
use App\Http\Controllers\Controller;
use App\Http\Resources\ChannelResource;
use App\Models\Channel;
use App\Models\Client;
use App\Models\User;
use App\Support\Access;
use App\Support\Realtime;
use App\Support\Unread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Project channels (under a client) and direct messages. */
class ChannelController extends Controller
{
    /**
     * Every channel the user can open, with member ids. Admins also get archived projects
     * (marked with archived_at) so they can restore them.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $channels = Channel::query()
            ->where(fn ($q) => $q
                ->whereIn('id', Channel::query()->accessibleTo($user)->select('channels.id'))
                ->when(Access::canManageClient($user), fn ($q) => $q->orWhere('kind', ChannelKind::Project->value)))
            ->with('members:id')
            ->orderBy('name')
            ->get();

        return ChannelResource::collection($channels);
    }

    public function show(Request $request, Channel $channel): ChannelResource
    {
        abort_unless(Access::canSeeChannel($request->user(), $channel), 404);

        return new ChannelResource($channel->load('members:id'));
    }

    /** New project under a client (admins only). Names are unique per client, ignoring case. */
    public function store(Request $request): ChannelResource
    {
        abort_unless(Access::canManageClient($request->user()), 403, 'Only admins can create projects.');
        $data = $request->validate([
            'client_id' => ['required', 'uuid', Rule::exists('clients', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'is_private' => ['sometimes', 'boolean'],
        ]);
        $name = trim($data['name']);
        abort_if($name === '', 422, 'The name is required.');
        $this->ensureUniqueName($data['client_id'], $name);

        $channel = Channel::create([
            'kind' => ChannelKind::Project,
            'client_id' => $data['client_id'],
            'name' => $name,
            'is_private' => $data['is_private'] ?? false,
            'created_by' => $request->user()->id,
        ]);
        Realtime::send(new DirectoryChanged);

        return new ChannelResource($channel->load('members:id'));
    }

    public function update(Request $request, Channel $channel): ChannelResource
    {
        abort_unless(Access::canManageChannel($request->user(), $channel), 403, 'Only admins can change projects.');
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'is_private' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
            abort_if($data['name'] === '', 422, 'The name is required.');
            $this->ensureUniqueName($channel->client_id, $data['name'], $channel->id);
        }

        $channel->update($data);
        Realtime::send(new DirectoryChanged);

        return new ChannelResource($channel->load('members:id'));
    }

    /** Archive or restore a project. Archived projects are invisible to everyone but admins. */
    public function setArchived(Request $request, Channel $channel): ChannelResource
    {
        abort_unless(Access::canManageChannel($request->user(), $channel), 403, 'Not allowed to archive this project.');
        $data = $request->validate(['archived' => ['required', 'boolean']]);

        $channel->archived_at = $data['archived'] ? now() : null;
        $channel->save();
        Realtime::send(new DirectoryChanged);

        return new ChannelResource($channel->load('members:id'));
    }

    /** Find or create the 1:1 DM between the caller and another person. Viewers can DM too. */
    public function dm(Request $request): ChannelResource
    {
        $me = $request->user();
        $data = $request->validate(['user_id' => ['required', 'uuid']]);
        abort_if($data['user_id'] === $me->id, 422, 'You cannot start a DM with yourself.');
        $other = User::query()->whereKey($data['user_id'])->where('is_active', true)->first();
        abort_unless($other, 422, 'Unknown user.');

        // Serialise concurrent calls for the same pair so we never create duplicates.
        $pair = collect([$me->id, $other->id])->sort()->implode(':');
        $channel = Cache::lock("dm:{$pair}", 10)->block(5, function () use ($me, $other) {
            $existing = Channel::query()
                ->where('kind', ChannelKind::Dm->value)
                ->whereHas('members', fn ($q) => $q->whereKey($me->id))
                ->whereHas('members', fn ($q) => $q->whereKey($other->id))
                ->has('members', '=', 2)
                ->first();
            if ($existing) {
                return [$existing, false];
            }

            return DB::transaction(function () use ($me, $other) {
                $channel = Channel::create(['kind' => ChannelKind::Dm, 'is_private' => true, 'created_by' => $me->id]);
                $channel->members()->attach([$me->id, $other->id]);

                return [$channel, true];
            });
        });

        [$dm, $created] = $channel;
        if ($created) {
            Realtime::send(new DirectoryChanged);
        }

        return new ChannelResource($dm->load('members:id'));
    }

    public function markRead(Request $request, Channel $channel): JsonResponse
    {
        abort_unless(Access::canAccessChannel($request->user(), $channel), 404);
        Unread::markRead($request->user(), $channel->id);

        return response()->json(['ok' => true]);
    }

    /** { unread: {channel_id: n}, mentions: {channel_id: n} } for channels with something unread. */
    public function unread(Request $request): JsonResponse
    {
        return response()->json(Unread::counts($request->user()));
    }

    private function ensureUniqueName(string $clientId, string $name, ?string $exceptId = null): void
    {
        $taken = Channel::query()
            ->where('client_id', $clientId)
            ->where('kind', ChannelKind::Project->value)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();
        abort_if($taken, 409, 'This client already has a project with that name.');
    }
}
