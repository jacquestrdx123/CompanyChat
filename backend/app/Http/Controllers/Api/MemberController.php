<?php

namespace App\Http\Controllers\Api;

use App\Events\DirectoryChanged;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Client;
use App\Models\User;
use App\Support\Access;
use App\Support\Realtime;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Who is in a client or a project. Anyone who can see the client/project sees its members;
 * only admins change them. DM membership never changes.
 */
class MemberController extends Controller
{
    public function clientIndex(Request $request, Client $client): JsonResponse
    {
        abort_unless(Access::canSeeClient($request->user(), $client), 404);

        return $this->list($client->members());
    }

    public function clientStore(Request $request, Client $client): JsonResponse
    {
        abort_unless(Access::canManageClient($request->user()), 403, 'Only admins can change members.');

        return $this->add($request, $client->members());
    }

    public function clientUpdate(Request $request, Client $client, User $user): JsonResponse
    {
        abort_unless(Access::canManageClient($request->user()), 403, 'Only admins can change members.');

        return $this->changeRole($request, $client->members(), $user);
    }

    public function clientDestroy(Request $request, Client $client, User $user): JsonResponse
    {
        abort_unless(Access::canManageClient($request->user()), 403, 'Only admins can change members.');

        return $this->remove($client->members(), $user);
    }

    public function channelIndex(Request $request, Channel $channel): JsonResponse
    {
        abort_unless(Access::canSeeChannel($request->user(), $channel), 404);

        return $this->list($channel->members());
    }

    public function channelStore(Request $request, Channel $channel): JsonResponse
    {
        abort_unless(Access::canManageChannel($request->user(), $channel), 403, 'Only admins can change project members.');

        return $this->add($request, $channel->members());
    }

    public function channelUpdate(Request $request, Channel $channel, User $user): JsonResponse
    {
        abort_unless(Access::canManageChannel($request->user(), $channel), 403, 'Only admins can change project members.');

        return $this->changeRole($request, $channel->members(), $user);
    }

    public function channelDestroy(Request $request, Channel $channel, User $user): JsonResponse
    {
        abort_unless(Access::canManageChannel($request->user(), $channel), 403, 'Only admins can change project members.');

        return $this->remove($channel->members(), $user);
    }

    private function list(BelongsToMany $members): JsonResponse
    {
        return response()->json($members->get()->map(fn (User $u) => ['user_id' => $u->id, 'role' => $u->pivot->role])->values());
    }

    private function add(Request $request, BelongsToMany $members): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'uuid', Rule::exists('users', 'id')],
            'role' => ['sometimes', Rule::in(['manager', 'member'])],
        ]);
        abort_if((clone $members)->whereKey($data['user_id'])->exists(), 409, 'Already a member.');

        $members->attach($data['user_id'], ['role' => $data['role'] ?? 'member']);
        Realtime::send(new DirectoryChanged);

        return response()->json(['user_id' => $data['user_id'], 'role' => $data['role'] ?? 'member'], 201);
    }

    private function changeRole(Request $request, BelongsToMany $members, User $user): JsonResponse
    {
        $data = $request->validate(['role' => ['required', Rule::in(['manager', 'member'])]]);
        abort_unless($members->updateExistingPivot($user->id, ['role' => $data['role']]), 404, 'Not a member.');

        return response()->json(['user_id' => $user->id, 'role' => $data['role']]);
    }

    private function remove(BelongsToMany $members, User $user): JsonResponse
    {
        $members->detach($user->id);
        Realtime::send(new DirectoryChanged);

        return response()->json(['ok' => true]);
    }
}
