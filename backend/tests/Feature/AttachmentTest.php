<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Ported from tests/attachments.test.ts. */
class AttachmentTest extends TestCase
{
    public function test_upload_sign_download_and_delete(): void
    {
        Storage::fake('attachments');
        ['member' => $member, 'outsider' => $outsider, 'client' => $client, 'public' => $public] = $this->office();

        $res = $this->actingAs($member)->post("/api/channels/{$public->id}/messages", [
            'body' => '',
            'files' => [UploadedFile::fake()->image('photo.png'), UploadedFile::fake()->create('my report.pdf', 20, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertCreated();

        $atts = $res->json('message_attachments');
        $this->assertCount(2, $atts);
        $this->assertSame("{$public->id}/{$res->json('id')}/1_my_report.pdf", $atts[1]['path']);
        Storage::disk('attachments')->assertExists($atts[0]['path']);

        $ids = array_column($atts, 'id');
        $this->actingAs($outsider)->postJson('/api/attachments/urls', ['ids' => $ids])->assertJsonPath('urls', []);
        $urls = $this->actingAs($member)->postJson('/api/attachments/urls', ['ids' => $ids])->assertOk()->json('urls');
        $this->assertCount(2, $urls);

        $this->app['auth']->forgetGuards();
        $this->get($urls[$atts[0]['id']])->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('attachment', $this->get($urls[$atts[1]['id']])->headers->get('Content-Disposition'));
        $this->get(str_replace('signature=', 'signature=x', $urls[$atts[0]['id']]))->assertForbidden();

        // someone else in the channel cannot delete it; the author can, and the message stays
        $other = User::factory()->create();
        $client->members()->attach($other->id);
        $this->actingAs($other)->deleteJson("/api/attachments/{$atts[0]['id']}")->assertForbidden();
        $this->actingAs($member)->deleteJson("/api/attachments/{$atts[0]['id']}")->assertOk();
        Storage::disk('attachments')->assertMissing($atts[0]['path']);
        $this->assertDatabaseHas('messages', ['id' => $res->json('id')]);
        $this->actingAs($member)->getJson("/api/channels/{$public->id}/messages")
            ->assertJsonPath('0.message_attachments.0.deleted_at', fn ($v) => $v !== null);
    }

    public function test_file_rules(): void
    {
        Storage::fake('attachments');
        ['member' => $member, 'public' => $public] = $this->office();

        $this->actingAs($member)->post("/api/channels/{$public->id}/messages", [
            'files' => [UploadedFile::fake()->create('big.bin', 11 * 1024)],
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->actingAs($member)->post("/api/channels/{$public->id}/messages", [
            'files' => array_map(fn ($i) => UploadedFile::fake()->create("f{$i}.txt", 1), range(1, 6)),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_deleting_a_message_removes_its_files(): void
    {
        Storage::fake('attachments');
        ['member' => $member, 'public' => $public] = $this->office();
        $res = $this->actingAs($member)->post("/api/channels/{$public->id}/messages", [
            'files' => [UploadedFile::fake()->create('a.txt', 1)],
        ], ['Accept' => 'application/json']);

        $this->actingAs($member)->deleteJson("/api/messages/{$res->json('id')}")->assertOk();
        Storage::disk('attachments')->assertMissing($res->json('message_attachments.0.path'));
        $this->assertDatabaseCount('message_attachments', 0);
    }
}
