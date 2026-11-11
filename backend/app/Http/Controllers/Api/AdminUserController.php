<?php

namespace App\Http\Controllers\Api;

use App\Enums\AppRole;
use App\Events\ProfileUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\InviteNotification;
use App\Support\Realtime;
use App\Support\UserPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Admin user management (replaces the admin-users Edge Function and set_app_role):
 * list, invite, resend invite, password reset email, deactivate, reactivate, change role.
 */
class AdminUserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::query()->orderBy('display_name')->get();

        return response()->json($users->map(fn (User $u) => [
            ...(new UserResource($u))->resolve(request()),
            'email' => $u->email,
            'pending' => ! $u->hasJoined(), // has not accepted the invite yet
            'last_sign_in_at' => $u->last_sign_in_at?->toISOString(),
        ]));
    }

    public function invite(Request $request): JsonResponse
    {
        $this->deny(UserPolicy::canInvite($request->user()));

        $data = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'display_name' => ['nullable', 'string', 'max:80'],
        ]);
        $email = Str::lower(trim($data['email']));
        if (User::query()->where('email', $email)->exists()) {
            return response()->json(['message' => 'That email already has an account.'], 409);
        }
        $name = trim($data['display_name'] ?? '') ?: Str::before($email, '@');

        $user = User::create(['email' => $email, 'display_name' => Str::limit($name, 80, '')]);
        $this->sendInvite($user, $request->user());

        return response()->json(['ok' => true, 'user' => (new UserResource($user))->resolve($request)], 201);
    }

    public function resendInvite(Request $request, User $user): JsonResponse
    {
        $this->deny(UserPolicy::canInvite($request->user()));
        if ($user->hasJoined()) {
            return response()->json(['message' => 'That person has already joined, so there is no invite to resend.'], 409);
        }
        $this->sendInvite($user, $request->user());

        return response()->json(['ok' => true]);
    }

    /** For people who joined but need a new password: email them a reset link. */
    public function sendPasswordReset(Request $request, User $user): JsonResponse
    {
        $this->deny(UserPolicy::canInvite($request->user()));
        if (! $user->hasJoined()) {
            return response()->json(['message' => 'That person has not accepted their invite yet. Resend the invite instead.'], 409);
        }
        $status = Password::broker('users')->sendResetLink(['email' => $user->email]);
        if ($status !== Password::RESET_LINK_SENT) {
            return response()->json(['message' => __($status)], 429);
        }

        return response()->json(['ok' => true]);
    }

    public function deactivate(Request $request, User $user): JsonResponse
    {
        return $this->setActive($request, $user, false);
    }

    public function reactivate(Request $request, User $user): JsonResponse
    {
        return $this->setActive($request, $user, true);
    }

    public function setRole(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['role' => ['required', Rule::enum(AppRole::class)]]);
        $role = AppRole::from($data['role']);
        $this->deny(UserPolicy::canSetRole($request->user(), $user, $role));

        $user->app_role = $role;
        $user->save();
        Realtime::send(new ProfileUpdated($user));

        return response()->json(['ok' => true]);
    }

    private function setActive(Request $request, User $user, bool $active): JsonResponse
    {
        $this->deny(UserPolicy::canChangeActive($request->user(), $user));

        $user->is_active = $active;
        $user->save();
        if (! $active) {
            $user->tokens()->delete(); // signed out everywhere, right now
        }
        Realtime::send(new ProfileUpdated($user));

        return response()->json(['ok' => true]);
    }

    private function sendInvite(User $user, User $by): void
    {
        $token = Password::broker('invites')->createToken($user);
        $user->notify(new InviteNotification($token, $by->display_name));
    }

    private function deny(?string $reason): void
    {
        abort_if($reason !== null, 403, $reason ?? '');
    }
}
