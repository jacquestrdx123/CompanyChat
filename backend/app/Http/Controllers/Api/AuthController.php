<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Sign-in with bearer tokens (Sanctum personal access tokens), replacing Supabase Auth.
 * Tokens work the same for the browser, the PWA and the Electron desktop app.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::query()->where('email', Str::lower(trim($data['email'])))->first();
        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Invalid login credentials.']);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'Your account has been deactivated.']);
        }

        $user->forceFill(['last_sign_in_at' => now(), 'joined_at' => $user->joined_at ?? now()])->save();

        return $this->tokenResponse($user, $data['device_name'] ?? null);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        if (method_exists($token, 'delete')) {
            $token->delete();
        }

        return response()->json(['ok' => true]);
    }

    /** Self-service "forgot password". Always answers the same, so it never reveals who has an account. */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $user = User::query()->where('email', Str::lower(trim($data['email'])))->where('is_active', true)->first();
        if ($user?->hasJoined()) {
            Password::broker('users')->sendResetLink(['email' => $user->email]);
        }

        return response()->json(['ok' => true]);
    }

    /** Finish a password reset; signs the person in. */
    public function resetPassword(Request $request): JsonResponse
    {
        return $this->completeWithToken($request, 'users');
    }

    /** Accept an invite by choosing a password; signs the person in. */
    public function acceptInvite(Request $request): JsonResponse
    {
        return $this->completeWithToken($request, 'invites');
    }

    private function completeWithToken(Request $request, string $broker): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', PasswordRule::min(8)],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $signedIn = null;
        $status = Password::broker($broker)->reset(
            ['email' => Str::lower(trim($data['email'])), 'token' => $data['token'], 'password' => $data['password']],
            function (User $user, string $password) use (&$signedIn) {
                if (! $user->is_active) {
                    return;
                }
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    'joined_at' => $user->joined_at ?? now(),
                    'last_sign_in_at' => now(),
                ])->save();
                $user->tokens()->delete(); // a new password signs out every other device
                event(new PasswordReset($user));
                $signedIn = $user;
            },
        );

        if (! $signedIn) {
            throw ValidationException::withMessages([
                'token' => $status === Password::PASSWORD_RESET ? 'Your account has been deactivated.' : 'This link is invalid or has expired. Ask an admin to send you a new one.',
            ]);
        }

        return $this->tokenResponse($signedIn, $data['device_name'] ?? null);
    }

    private function tokenResponse(User $user, ?string $device): JsonResponse
    {
        $token = $user->createToken($device ?: 'app')->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => (new UserResource($user))->resolve(request()),
        ]);
    }
}
