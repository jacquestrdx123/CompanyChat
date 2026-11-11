<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImportFromSupabaseTest extends TestCase
{
    public function test_imports_rows_with_their_ids_and_passwords(): void
    {
        Storage::fake('attachments');
        config(['database.connections.supabase_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $src = DB::connection('supabase_test');
        $src->statement("attach database ':memory:' as auth");
        $src->statement('create table auth.users (id text, encrypted_password text, email_confirmed_at text, last_sign_in_at text)');
        $src->statement('create table profiles (id text, email text, display_name text, avatar_url text, app_role text, status text, is_active int, created_at text)');
        $src->statement('create table clients (id text, name text, created_by text, archived_at text, created_at text)');
        $src->statement('create table client_members (client_id text, user_id text, role text, created_at text)');
        $src->statement('create table channels (id text, kind text, client_id text, name text, is_private int, created_by text, archived_at text, created_at text)');
        $src->statement('create table channel_members (channel_id text, user_id text, role text, created_at text)');
        $src->statement('create table messages (id text, channel_id text, user_id text, body text, created_at text, edited_at text)');
        $src->statement('create table message_attachments (id text, message_id text, channel_id text, path text, file_name text, mime_type text, size_bytes int, deleted_at text, created_at text)');
        $src->statement('create table message_mentions (message_id text, user_id text, channel_id text, created_at text)');
        $src->statement('create table channel_reads (user_id text, channel_id text, last_read_at text)');
        $src->statement('create table channel_watches (user_id text, channel_id text, created_at text)');
        $src->statement('create table push_subscriptions (id text, user_id text, endpoint text, p256dh text, auth text, show_preview int, user_agent text, created_at text)');

        [$u, $cl, $ch, $m, $a] = [Str::uuid(), Str::uuid(), Str::uuid(), Str::uuid(), Str::uuid()];
        $ts = '2026-11-01 10:00:00';
        $src->table('auth.users')->insert(['id' => $u, 'encrypted_password' => '$2a$'.substr(password_hash('hunter22', PASSWORD_BCRYPT), 4), 'email_confirmed_at' => $ts, 'last_sign_in_at' => $ts]);
        $src->table('profiles')->insert(['id' => $u, 'email' => 'Boss@Example.com', 'display_name' => 'Boss', 'app_role' => 'super_admin', 'status' => 'online', 'is_active' => 1, 'created_at' => $ts]);
        $src->table('clients')->insert(['id' => $cl, 'name' => 'Acme', 'created_by' => $u, 'created_at' => $ts]);
        $src->table('client_members')->insert(['client_id' => $cl, 'user_id' => $u, 'role' => 'member', 'created_at' => $ts]);
        $src->table('channels')->insert(['id' => $ch, 'kind' => 'project', 'client_id' => $cl, 'name' => 'web', 'is_private' => 0, 'created_by' => $u, 'created_at' => $ts]);
        $src->table('messages')->insert(['id' => $m, 'channel_id' => $ch, 'user_id' => $u, 'body' => 'hello', 'created_at' => $ts]);
        $src->table('message_attachments')->insert(['id' => $a, 'message_id' => $m, 'channel_id' => $ch, 'path' => "{$ch}/{$m}/0_a.txt", 'file_name' => 'a.txt', 'size_bytes' => 2, 'created_at' => $ts]);

        $files = sys_get_temp_dir().'/supa-'.Str::random(6);
        mkdir("{$files}/{$ch}/{$m}", 0777, true);
        file_put_contents("{$files}/{$ch}/{$m}/0_a.txt", 'hi');

        $this->artisan('chat:import-supabase', ['--connection' => 'supabase_test', '--files' => $files])->assertSuccessful();

        $this->assertDatabaseHas('messages', ['id' => $m, 'body' => 'hello']);
        Storage::disk('attachments')->assertExists("{$ch}/{$m}/0_a.txt");
        $this->postJson('/api/auth/login', ['email' => 'boss@example.com', 'password' => 'hunter22'])->assertOk();
        $this->assertSame('super_admin', User::find($u)->app_role->value);
    }
}
