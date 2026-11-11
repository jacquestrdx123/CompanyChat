<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Plain JSON bodies (no {"data": ...} wrapper), closer to what the app got from Supabase.
        JsonResource::withoutWrapping();

        // Password reset emails link to the Nuxt app, which posts the token to /api/auth/reset-password.
        ResetPassword::createUrlUsing(fn ($user, string $token) => config('app.frontend_url').'/reset-password?'
            .http_build_query(['token' => $token, 'email' => $user->email]));

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('messages', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
    }
}
