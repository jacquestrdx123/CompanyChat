<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Ported from tests/unread.test.ts and watches.test.ts. */
class UnreadAndWatchTest extends TestCase
{
    public function test_unread_counts_and_mark_read(): void
    {
        ['member' => $member, 'client' => $client, 'public' => $public] = $this->office();
        $other = User::factory()->create();
        $client->members()->attach($other->id);

        // An old project shows as read the first time you look.
        Message::factory()->for($public)->for($other)->create(['created_at' => now()->subDay()]);
        $this->actingAs($member)->getJson('/api/unread')->assertExactJson(['unread' => [], 'mentions' => []]);

        $this->actingAs($other)->postJson("/api/channels/{$public->id}/messages", ['body' => "hi @[{$member->id}]"]);
        $this->actingAs($other)->postJson("/api/channels/{$public->id}/messages", ['body' => 'again']);
        $this->actingAs($member)->postJson("/api/channels/{$public->id}/messages", ['body' => 'my own']);

        $this->actingAs($member)->getJson('/api/unread')
            ->assertJsonPath("unread.{$public->id}", 2)
            ->assertJsonPath("mentions.{$public->id}", 1);

        $this->actingAs($member)->postJson("/api/channels/{$public->id}/read")->assertOk();
        $this->actingAs($member)->getJson('/api/unread')->assertExactJson(['unread' => [], 'mentions' => []]);
    }

    public function test_a_new_dm_counts_from_its_start(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $dm = $this->actingAs($a)->postJson('/api/dms', ['user_id' => $b->id])->json('id');
        $this->actingAs($a)->postJson("/api/channels/{$dm}/messages", ['body' => 'hello']);

        $this->actingAs($b)->getJson('/api/unread')->assertJsonPath("unread.{$dm}", 1);
    }

    public function test_watches(): void
    {
        ['member' => $member, 'outsider' => $outsider, 'public' => $public, 'private' => $private] = $this->office();

        $this->actingAs($member)->putJson("/api/channels/{$public->id}/watch")->assertOk();
        $this->actingAs($member)->putJson("/api/channels/{$public->id}/watch")->assertOk(); // idempotent
        $this->actingAs($member)->putJson("/api/channels/{$private->id}/watch")->assertNotFound();
        $this->actingAs($member)->getJson('/api/watches')->assertExactJson([$public->id]);
        $this->actingAs($outsider)->getJson('/api/watches')->assertExactJson([]);

        $this->actingAs($member)->deleteJson("/api/channels/{$public->id}/watch")->assertOk();
        $this->assertSame(0, DB::table('channel_watches')->count());
    }
}
