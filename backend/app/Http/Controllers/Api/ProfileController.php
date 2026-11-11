<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserStatus;
use App\Events\ProfileUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Support\Realtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;

/** The signed-in person's own profile. */
class ProfileController extends Controller
{
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /** Name, avatar and status only; the role is never self-editable. */
    public function update(Request $request): UserResource
    {
        $data = $request->validate([
            'display_name' => ['sometimes', 'string', 'min:1', 'max:80'],
            'avatar_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'status' => ['sometimes', Rule::enum(UserStatus::class)],
        ]);
        if (isset($data['display_name'])) {
            $data['display_name'] = trim($data['display_name']);
        }

        $user = $request->user();
        $user->fill($data)->save();
        if ($user->wasChanged()) {
            Realtime::send(new ProfileUpdated($user));
        }

        return new UserResource($user);
    }

    /** Set a new password while signed in (the set-password page). Other devices are signed out. */
    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', PasswordRule::min(8)]]);

        $user = $request->user();
        $user->forceFill(['password' => $data['password'], 'joined_at' => $user->joined_at ?? now()])->save();

        $current = $user->currentAccessToken();
        $user->tokens()->when($current?->id ?? null, fn ($q, $id) => $q->where('id', '!=', $id))->delete();

        return response()->json(['ok' => true]);
    }
}
