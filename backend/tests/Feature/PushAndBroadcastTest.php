<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Support\Mentions;
use App\Support\PushMessage;
use App\Support\PushTargets;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Ported from tests/push.test.ts and push-payload.test.ts, plus Reverb channel authorisation. */
class PushAndBroadcastTest extends TestCase
{
    public function test_push_targets(): void
    {
        ['member' => $member, 'client' => $client, 'public' => $public] = $this->office();
        $watcher = User::factory()->create();
        $mentioned = User::factory()->create();
        $dnd = User::factory()->create(['status' => 'dnd']);
        $bystander = User::factory()->create();
        $client->members()->attach([$watcher->id, $mentioned->id, $dnd->id, $bystander->id]);
        DB::table('channel_watches')->insert([
            ['user_id' => $watcher->id, 'channel_id' => $public->id, 'created_at' => now()],
            ['user_id' => $dnd->id, 'channel_id' => $public->id, 'created_at' => now()],
            ['user_id' => $member->id, 'channel_id' => $public->id, 'created_at' => now()],
        ]);

        $msg = Message::factory()->for($public)->for($member)->create(['body' => "hey @[{$mentioned->id}]"]);
        Mentions::sync($msg);

        $this->assertEquals([$mentioned->id => 'mention', $watcher->id => 'watch'], PushTargets::for($msg));

        $dm = Channel::factory()->dm()->create();
        $dm->members()->attach([$member->id, $bystander->id]);
        $dmMsg = Message::factory()->for($dm)->for($member)->create();
        $this->assertEquals([$bystander->id => 'dm'], PushTargets::for($dmMsg));
    }

    public function test_push_payload(): void
    {
        $id = '11111111-1111-1111-1111-111111111111';
        $nameOf = fn ($x) => $x === $id ? 'Sam' : 'Unknown';

        $this->assertSame(
            ['title' => 'Pat', 'body' => 'hi @Sam', 'tag' => 'c1', 'url' => '/c/c1'],
            PushMessage::build('dm', 'c1', 'dm', null, 'Pat', "hi @[{$id}]", true, $nameOf),
        );
        $this->assertSame('Pat mentioned you', PushMessage::build('mention', 'c1', 'project', 'web', 'Pat', 'x', false, $nameOf)['body']);
        $this->assertSame('# web', PushMessage::build('watch', 'c1', 'project', 'web', 'Pat', '', true, $nameOf)['title']);
        $this->assertSame('Pat: Sent an attachment', PushMessage::build('watch', 'c1', 'project', 'web', 'Pat', '', true, $nameOf)['body']);
        $this->assertSame(140, mb_strlen(PushMessage::truncate(str_repeat('a', 300))));
    }

    public function test_push_subscriptions_move_between_users(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $sub = ['endpoint' => 'https://push.example.com/abc', 'keys' => ['p256dh' => 'k', 'auth' => 'a']];

        $this->actingAs($a)->postJson('/api/push-subscriptions', [...$sub, 'endpoint' => 'http://insecure'])->assertStatus(422);
        $this->actingAs($a)->postJson('/api/push-subscriptions', $sub)->assertOk();
        $this->actingAs($b)->postJson('/api/push-subscriptions', $sub)->assertOk();
        $this->assertDatabaseCount('push_subscriptions', 1);
        $this->assertDatabaseHas('push_subscriptions', ['user_id' => $b->id]);

        $this->actingAs($b)->patchJson('/api/push-subscriptions', ['endpoint' => $sub['endpoint'], 'show_preview' => false])->assertOk();
        $this->assertDatabaseHas('push_subscriptions', ['show_preview' => false]);
        $this->actingAs($a)->deleteJson('/api/push-subscriptions', ['endpoint' => $sub['endpoint']])->assertOk();
        $this->assertDatabaseCount('push_subscriptions', 1); // not a's to remove
        $this->actingAs($b)->deleteJson('/api/push-subscriptions', ['endpoint' => $sub['endpoint']])->assertOk();
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_reverb_channel_authorisation(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'k', 'broadcasting.connections.reverb.secret' => 's', 'broadcasting.connections.reverb.app_id' => '1']);
        Broadcast::purge('reverb');
        require base_path('routes/channels.php'); // register the channel callbacks on the reverb driver
        ['member' => $member, 'public' => $public, 'private' => $private] = $this->office();

        $auth = fn (string $name) => $this->actingAs($member)->postJson('/api/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => $name]);

        $auth("private-channel.{$public->id}")->assertOk();
        $auth("private-channel.{$private->id}")->assertForbidden();
        $auth("private-user.{$member->id}")->assertOk();
        $auth('private-user.'.User::factory()->create()->id)->assertForbidden();
        $auth('presence-office')->assertOk()->assertJsonPath('channel_data', fn ($d) => str_contains($d, $member->id));
    }
}
