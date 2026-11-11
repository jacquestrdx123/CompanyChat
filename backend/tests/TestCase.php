<?php

namespace Tests;

use App\Models\Channel;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** A client with a public and a private project; $member belongs to the client only. */
    protected function office(): array
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $client = Client::factory()->create();
        $client->members()->attach($member->id);
        $public = Channel::factory()->for($client)->create(['name' => 'general']);
        $private = Channel::factory()->for($client)->private()->create(['name' => 'secret']);

        return compact('admin', 'member', 'outsider', 'client', 'public', 'private');
    }
}
