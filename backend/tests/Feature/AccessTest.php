<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\User;
use App\Support\Access;
use Tests\TestCase;

/** Ported from tests/rls.test.ts, roles.test.ts and archive.test.ts. */
class AccessTest extends TestCase
{
    public function test_channel_visibility_follows_client_membership_and_privacy(): void
    {
        ['admin' => $admin, 'member' => $member, 'outsider' => $outsider, 'public' => $public, 'private' => $private] = $this->office();

        $this->assertTrue(Access::canAccessChannel($member, $public));
        $this->assertFalse(Access::canAccessChannel($member, $private));
        $this->assertFalse(Access::canAccessChannel($outsider, $public));
        $this->assertTrue(Access::canAccessChannel($admin, $private));

        $private->members()->attach($member->id);
        $this->assertTrue(Access::canAccessChannel($member, $private));
    }

    public function test_dms_are_for_participants_only_even_admins(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $dm = Channel::factory()->dm()->create();
        $dm->members()->attach([$a->id, $b->id]);

        $this->assertTrue(Access::canAccessChannel($a, $dm));
        $this->assertFalse(Access::canAccessChannel($admin, $dm));
    }

    public function test_archived_projects_and_clients_are_inaccessible_to_everyone(): void
    {
        ['admin' => $admin, 'member' => $member, 'client' => $client, 'public' => $public] = $this->office();

        $public->forceFill(['archived_at' => now()])->save();
        $this->assertFalse(Access::canAccessChannel($member, $public));
        $this->assertFalse(Access::canAccessChannel($admin, $public));
        $this->assertTrue(Access::canSeeChannel($admin, $public)); // to restore it

        $public->forceFill(['archived_at' => null])->save();
        $client->forceFill(['archived_at' => now()])->save();
        $this->assertFalse(Access::canAccessChannel($member, $public));
    }

    public function test_deactivated_users_see_nothing_and_are_rejected_by_the_api(): void
    {
        ['member' => $member, 'public' => $public] = $this->office();
        $member->is_active = false;
        $member->save();

        $this->assertFalse(Access::canAccessChannel($member->fresh(), $public));
        $this->actingAs($member)->getJson('/api/channels')->assertForbidden();
    }

    public function test_viewers_read_projects_but_only_post_in_dms(): void
    {
        ['client' => $client, 'public' => $public] = $this->office();
        $viewer = User::factory()->viewer()->create();
        $other = User::factory()->create();
        $client->members()->attach($viewer->id);

        $this->actingAs($viewer)->getJson("/api/channels/{$public->id}/messages")->assertOk();
        $this->actingAs($viewer)->postJson("/api/channels/{$public->id}/messages", ['body' => 'hi'])->assertForbidden();

        $dm = $this->actingAs($viewer)->postJson('/api/dms', ['user_id' => $other->id])->assertSuccessful()->json('id');
        $this->actingAs($viewer)->postJson("/api/channels/{$dm}/messages", ['body' => 'question'])->assertCreated();
    }

    public function test_channel_list_only_contains_what_you_can_see(): void
    {
        ['admin' => $admin, 'member' => $member, 'public' => $public, 'private' => $private] = $this->office();
        $private->forceFill(['archived_at' => now()])->save();

        $this->actingAs($member)->getJson('/api/channels')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $public->id);
        // admins also get archived projects so they can restore them
        $this->actingAs($admin)->getJson('/api/channels')->assertOk()->assertJsonCount(2);
    }

    public function test_only_admins_create_clients_and_projects(): void
    {
        ['admin' => $admin, 'member' => $member, 'client' => $client] = $this->office();

        $this->actingAs($member)->postJson('/api/clients', ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($member)->postJson('/api/channels', ['client_id' => $client->id, 'name' => 'x'])->assertForbidden();

        $this->actingAs($admin)->postJson('/api/clients', ['name' => 'Acme'])->assertCreated();
        $this->actingAs($admin)->postJson('/api/channels', ['client_id' => $client->id, 'name' => 'Website'])->assertCreated();
        $this->actingAs($admin)->postJson('/api/channels', ['client_id' => $client->id, 'name' => 'website'])->assertStatus(409);
    }

    public function test_members_see_only_their_active_clients(): void
    {
        ['admin' => $admin, 'member' => $member, 'client' => $client] = $this->office();
        $this->actingAs($member)->getJson('/api/clients')->assertJsonCount(1);

        $this->actingAs($admin)->putJson("/api/clients/{$client->id}/archived", ['archived' => true])->assertOk();
        $this->actingAs($member)->getJson('/api/clients')->assertJsonCount(0);
        $this->actingAs($admin)->getJson('/api/clients')->assertJsonCount(1)->assertJsonPath('0.archived_at', fn ($v) => $v !== null);
    }

    public function test_admins_manage_project_members(): void
    {
        ['admin' => $admin, 'member' => $member, 'outsider' => $outsider, 'private' => $private] = $this->office();

        $this->actingAs($member)->postJson("/api/channels/{$private->id}/members", ['user_id' => $outsider->id])->assertForbidden();
        $this->actingAs($admin)->postJson("/api/channels/{$private->id}/members", ['user_id' => $outsider->id])->assertCreated();
        $this->assertTrue(Access::canAccessChannel($outsider, $private));

        $this->actingAs($admin)->getJson("/api/channels/{$private->id}/members")->assertJsonCount(1);
        $this->actingAs($admin)->deleteJson("/api/channels/{$private->id}/members/{$outsider->id}")->assertOk();
        $this->assertFalse(Access::canAccessChannel($outsider, $private));
    }

    public function test_dm_is_found_not_duplicated(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        $first = $this->actingAs($a)->postJson('/api/dms', ['user_id' => $b->id])->json('id');
        $second = $this->actingAs($b)->postJson('/api/dms', ['user_id' => $a->id])->json('id');

        $this->assertSame($first, $second);
        $this->actingAs($a)->postJson('/api/dms', ['user_id' => $a->id])->assertStatus(422);
    }
}
