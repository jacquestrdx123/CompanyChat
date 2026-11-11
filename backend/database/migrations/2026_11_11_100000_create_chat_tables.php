<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The CompanyChat schema, ported from supabase/migrations. Row level security is replaced by
 * App\Support\Access and the policies in app/Policies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('client_members', function (Blueprint $table) {
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 10)->default('member'); // manager | member (kept, currently unused)
            $table->timestamps();
            $table->primary(['client_id', 'user_id']);
            $table->index('user_id');
        });

        // A channel is either a project under a client, or a direct message.
        Schema::create('channels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind', 10); // project | dm
            $table->foreignUuid('client_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->boolean('is_private')->default(false);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'kind']);
        });

        Schema::create('channel_members', function (Blueprint $table) {
            $table->foreignUuid('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 10)->default('member');
            $table->timestamps();
            $table->primary(['channel_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamp('created_at', 6)->useCurrent();
            $table->timestamp('updated_at', 6)->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->index(['channel_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // Word-prefix full-text search; @[uuid] mention tokens are not searchable text.
            DB::statement(<<<'SQL'
                alter table messages add column body_tsv tsvector generated always as (
                  to_tsvector('simple'::regconfig, regexp_replace(body, '@\[[0-9a-fA-F-]{36}\]', ' ', 'g'))
                ) stored
            SQL);
            DB::statement('create index messages_body_tsv_idx on messages using gin (body_tsv)');
        }

        Schema::create('message_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('message_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('channel_id')->constrained()->cascadeOnDelete();
            $table->string('path', 1024)->unique();
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->index('message_id');
        });

        Schema::create('message_mentions', function (Blueprint $table) {
            $table->foreignUuid('message_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('channel_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['message_id', 'user_id']);
            $table->index(['user_id', 'channel_id']);
        });

        // Per-user read markers, used for unread counts.
        Schema::create('channel_reads', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('channel_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_read_at', 6);
            $table->primary(['user_id', 'channel_id']);
        });

        // Watching a project = notifications for every message in it.
        Schema::create('channel_watches', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('channel_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['user_id', 'channel_id']);
        });

        // One row per browser/device that turned Web Push on.
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint', 2000);
            $table->string('endpoint_hash', 64)->unique(); // sha256(endpoint): long URLs can't be indexed directly on every driver
            $table->string('p256dh');
            $table->string('auth');
            $table->boolean('show_preview')->default(true);
            $table->string('user_agent', 300)->nullable();
            $table->timestamps();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        foreach (['push_subscriptions', 'channel_watches', 'channel_reads', 'message_mentions', 'message_attachments', 'messages', 'channel_members', 'channels', 'client_members', 'clients'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
