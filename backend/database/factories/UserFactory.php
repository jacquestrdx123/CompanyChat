<?php

namespace Database\Factories;

use App\Enums\AppRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'display_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'joined_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    public function role(AppRole $role): static
    {
        return $this->afterMaking(fn (User $u) => $u->app_role = $role);
    }

    public function superAdmin(): static
    {
        return $this->role(AppRole::SuperAdmin);
    }

    public function admin(): static
    {
        return $this->role(AppRole::Admin);
    }

    public function viewer(): static
    {
        return $this->role(AppRole::Viewer);
    }

    public function inactive(): static
    {
        return $this->afterMaking(fn (User $u) => $u->is_active = false);
    }

    /** Invited but has not chosen a password yet. */
    public function pending(): static
    {
        return $this->state(fn () => ['password' => null, 'joined_at' => null]);
    }
}
