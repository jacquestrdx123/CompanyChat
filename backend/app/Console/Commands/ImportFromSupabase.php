<?php

namespace App\Console\Commands;

use App\Support\Attachments;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * One-off move from Supabase. Copies every row with its original id, so links like /c/<channel id> keep
 * working. Passwords carry over: Supabase stores bcrypt hashes ($2a$), which are the same as PHP's $2y$.
 *
 * Attachments: download the "attachments" bucket first, then pass the folder with --files, e.g.
 *   npx supabase storage cp -r ss:///attachments ./supabase-files --experimental
 *   php artisan chat:import-supabase --files=./supabase-files
 */
class ImportFromSupabase extends Command
{
    protected $signature = 'chat:import-supabase
        {--connection=supabase : Database connection that points at the Supabase Postgres}
        {--files= : Folder holding a copy of the attachments bucket}
        {--fresh : Empty the Laravel tables first}';

    protected $description = 'Import users, clients, channels, messages and files from a Supabase project';

    /** Tables in dependency order, with the columns copied from Supabase's public schema. */
    private const TABLES = [
        'clients' => ['id', 'name', 'created_by', 'archived_at', 'created_at'],
        'client_members' => ['client_id', 'user_id', 'role', 'created_at'],
        'channels' => ['id', 'kind', 'client_id', 'name', 'is_private', 'created_by', 'archived_at', 'created_at'],
        'channel_members' => ['channel_id', 'user_id', 'role', 'created_at'],
        'messages' => ['id', 'channel_id', 'user_id', 'body', 'created_at', 'edited_at'],
        'message_attachments' => ['id', 'message_id', 'channel_id', 'path', 'file_name', 'mime_type', 'size_bytes', 'deleted_at', 'created_at'],
        'message_mentions' => ['message_id', 'user_id', 'channel_id', 'created_at'],
        'channel_reads' => ['user_id', 'channel_id', 'last_read_at'],
        'channel_watches' => ['user_id', 'channel_id', 'created_at'],
        'push_subscriptions' => ['id', 'user_id', 'endpoint', 'p256dh', 'auth', 'show_preview', 'user_agent', 'created_at'],
    ];

    public function handle(): int
    {
        $source = DB::connection($this->option('connection'));

        if ($this->option('fresh')) {
            if (! $this->confirm('This deletes every user, message and file record in the Laravel database. Continue?')) {
                return self::FAILURE;
            }
            foreach (array_reverse(array_keys(self::TABLES)) as $table) {
                DB::table($table)->delete();
            }
            DB::table('personal_access_tokens')->delete();
            DB::table('users')->delete();
        }

        DB::transaction(function () use ($source) {
            $this->importUsers($source);
            foreach (self::TABLES as $table => $columns) {
                $this->importTable($source, $table, $columns);
            }
        });

        if ($dir = $this->option('files')) {
            $this->copyFiles($dir);
        }

        $this->info('Done. People sign in with the same email and password as before.');

        return self::SUCCESS;
    }

    private function importUsers(Connection $source): void
    {
        $rows = $source->table('profiles as p')
            ->join('auth.users as u', 'u.id', '=', 'p.id')
            ->select('p.id', 'p.email', 'p.display_name', 'p.avatar_url', 'p.app_role', 'p.status', 'p.is_active', 'p.created_at',
                'u.encrypted_password', 'u.email_confirmed_at', 'u.last_sign_in_at')
            ->orderBy('p.created_at')
            ->get();

        $users = $rows->map(fn ($r) => [
            'id' => $r->id,
            'email' => mb_strtolower($r->email),
            'display_name' => mb_substr($r->display_name, 0, 80),
            'avatar_url' => $r->avatar_url,
            'app_role' => $r->app_role === 'owner' ? 'super_admin' : $r->app_role,
            'status' => $r->status,
            'is_active' => (bool) $r->is_active,
            'password' => self::bcrypt($r->encrypted_password),
            'joined_at' => ($r->email_confirmed_at && $r->encrypted_password) || $r->last_sign_in_at ? ($r->email_confirmed_at ?? $r->last_sign_in_at) : null,
            'last_sign_in_at' => $r->last_sign_in_at,
            'created_at' => $r->created_at,
            'updated_at' => now(),
        ])->all();

        foreach (array_chunk($users, 500) as $chunk) {
            DB::table('users')->upsert($chunk, ['id']);
        }
        $this->line(sprintf('  users: %d', count($users)));
    }

    /** Go's $2a$ bcrypt is identical to PHP's $2y$, but Laravel only accepts the $2y$ prefix. */
    public static function bcrypt(?string $hash): ?string
    {
        if (! $hash) {
            return null;
        }

        return str_starts_with($hash, '$2a$') || str_starts_with($hash, '$2b$') ? '$2y$'.substr($hash, 4) : $hash;
    }

    private function importTable(Connection $source, string $table, array $columns): void
    {
        $hasUpdatedAt = in_array('updated_at', DB::getSchemaBuilder()->getColumnListing($table), true);
        $count = 0;

        $source->table($table)->select($columns)->orderBy($columns[0])->chunk(1000, function ($rows) use ($table, $hasUpdatedAt, &$count) {
            $batch = $rows->map(function ($r) use ($table, $hasUpdatedAt) {
                $row = (array) $r;
                if ($table === 'push_subscriptions') {
                    $row['endpoint_hash'] = hash('sha256', $row['endpoint']);
                }
                if ($hasUpdatedAt) {
                    $row['updated_at'] = $row['edited_at'] ?? $row['created_at'] ?? now();
                }

                return $row;
            })->all();
            DB::table($table)->insertOrIgnore($batch);
            $count += count($batch);
        });

        $this->line(sprintf('  %s: %d', $table, $count));
    }

    private function copyFiles(string $dir): void
    {
        $dir = rtrim($dir, '/');
        $disk = Attachments::disk();
        $copied = $missing = 0;

        DB::table('message_attachments')->whereNull('deleted_at')->orderBy('id')->chunk(500, function ($rows) use ($dir, $disk, &$copied, &$missing) {
            foreach ($rows as $a) {
                $src = "{$dir}/{$a->path}";
                if (! File::exists($src)) {
                    $missing++;

                    continue;
                }
                $stream = fopen($src, 'r');
                $disk->writeStream($a->path, $stream);
                fclose($stream);
                $copied++;
            }
        });

        $this->line("  files: {$copied} copied, {$missing} not found in {$dir}");
    }
}
