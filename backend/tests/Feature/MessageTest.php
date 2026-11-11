<?php

namespace Tests\Feature;

use App\Events\MessageCreated;
use App\Events\MessageDeleted;
use App\Events\MessageUpdated;
use App\Events\UserMentioned;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** Ported from tests/messages.test.ts, editing.test.ts, mentions.test.ts. */
class MessageTest extends TestCase
{
    public function test_post_list_edit_and_delete(): void
    {
        Event::fake([MessageCreated::class, MessageUpdated::class, MessageDeleted::class]);
        ['member' => $member, 'public' => $public] = $this->office();

        $id = $this->actingAs($member)->postJson("/api/channels/{$public->id}/messages", ['body' => '  hello  '])
            ->assertCreated()->assertJsonPath('body', 'hello')->assertJsonPath('user_id', $member->id)->json('id');
        Event::assertDispatched(MessageCreated::class);

        $this->actingAs($member)->getJson("/api/channels/{$public->id}/messages")->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.message_attachments', []);

        $this->actingAs($member)->patchJson("/api/messages/{$id}", ['body' => 'edited'])
            ->assertOk()->assertJsonPath('body', 'edited')->assertJsonPath('edited_at', fn ($v) => $v !== null);
        Event::assertDispatched(MessageUpdated::class);

        $this->actingAs($member)->deleteJson("/api/messages/{$id}")->assertOk();
        Event::assertDispatched(MessageDeleted::class);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_empty_messages_and_outsiders_are_rejected(): void
    {
        ['member' => $member, 'outsider' => $outsider, 'public' => $public] = $this->office();

        $this->actingAs($member)->postJson("/api/channels/{$public->id}/messages", ['body' => '   '])->assertStatus(422);
        $this->actingAs($member)->postJson("/api/channels/{$public->id}/messages", ['body' => str_repeat('a', 4001)])->assertStatus(422);
        $this->actingAs($outsider)->postJson("/api/channels/{$public->id}/messages", ['body' => 'hi'])->assertNotFound();
        $this->actingAs($outsider)->getJson("/api/channels/{$public->id}/messages")->assertNotFound();
    }

    public function test_only_authors_edit_and_admins_can_moderate(): void
    {
        ['admin' => $admin, 'member' => $member, 'client' => $client, 'public' => $public] = $this->office();
        $other = User::factory()->create();
        $client->members()->attach($other->id);
        $msg = Message::factory()->for($public)->for($member)->create();

        $this->actingAs($other)->patchJson("/api/messages/{$msg->id}", ['body' => 'hacked'])->assertForbidden();
        $this->actingAs($other)->deleteJson("/api/messages/{$msg->id}")->assertForbidden();
        $this->actingAs($admin)->patchJson("/api/messages/{$msg->id}", ['body' => 'nope'])->assertForbidden();
        $this->actingAs($admin)->deleteJson("/api/messages/{$msg->id}")->assertOk();
    }

    public function test_pagination_and_jump(): void
    {
        ['member' => $member, 'public' => $public] = $this->office();
        $ids = [];
        foreach (range(1, 5) as $i) {
            $ids[] = Message::factory()->for($public)->for($member)->create(['body' => "m{$i}", 'created_at' => now()->subMinutes(10 - $i)])->id;
        }

        $this->actingAs($member)->getJson("/api/channels/{$public->id}/messages?limit=2")
            ->assertJsonPath('0.body', 'm4')->assertJsonPath('1.body', 'm5');
        $this->actingAs($member)->getJson("/api/channels/{$public->id}/messages?limit=2&before={$ids[3]}")
            ->assertJsonPath('0.body', 'm2')->assertJsonPath('1.body', 'm3');
        $this->actingAs($member)->getJson("/api/channels/{$public->id}/messages?from={$ids[1]}")
            ->assertJsonCount(4)->assertJsonPath('0.body', 'm2');
    }

    public function test_mentions_are_derived_from_the_body(): void
    {
        Event::fake([UserMentioned::class, MessageCreated::class, MessageUpdated::class]);
        ['member' => $member, 'outsider' => $outsider, 'client' => $client, 'admin' => $admin, 'public' => $public] = $this->office();
        $colleague = User::factory()->create();
        $client->members()->attach($colleague->id);

        $body = "hi @[{$colleague->id}] and @[{$outsider->id}] and myself @[{$member->id}]";
        $id = $this->actingAs($member)->postJson("/api/channels/{$public->id}/messages", ['body' => $body])->json('id');

        // the outsider cannot see the channel and the author never mentions themselves
        $this->assertDatabaseHas('message_mentions', ['message_id' => $id, 'user_id' => $colleague->id]);
        $this->assertDatabaseCount('message_mentions', 1);
        Event::assertDispatched(UserMentioned::class, fn ($e) => $e->userId === $colleague->id);

        // editing the mention away removes it; @channel mentions everyone who can see the channel
        $this->actingAs($member)->patchJson("/api/messages/{$id}", ['body' => 'hey @[channel]']);
        $mentioned = \DB::table('message_mentions')->where('message_id', $id)->pluck('user_id')->sort()->values()->all();
        $this->assertEqualsCanonicalizing([$colleague->id, $admin->id], $mentioned);
    }

    public function test_search_only_finds_accessible_messages(): void
    {
        ['member' => $member, 'public' => $public, 'private' => $private] = $this->office();
        Message::factory()->for($public)->for($member)->create(['body' => 'Quarterly report draft']);
        Message::factory()->for($private)->create(['body' => 'Quarterly secret']);

        $this->actingAs($member)->getJson('/api/search?q=quart rep')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.body', 'Quarterly report draft');
        $this->actingAs($member)->getJson('/api/search?q=')->assertJsonCount(0);
    }
}
