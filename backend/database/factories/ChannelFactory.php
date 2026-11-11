<?php

namespace Database\Factories;

use App\Enums\ChannelKind;
use App\Models\Channel;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Channel> */
class ChannelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'kind' => ChannelKind::Project,
            'client_id' => Client::factory(),
            'name' => fake()->unique()->words(2, true),
            'is_private' => false,
        ];
    }

    public function private(): static
    {
        return $this->state(['is_private' => true]);
    }

    public function dm(): static
    {
        return $this->state(['kind' => ChannelKind::Dm, 'client_id' => null, 'name' => null, 'is_private' => true]);
    }
}
