<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Users replace Supabase's auth.users + public.profiles: one row holds the login and the profile.
 * Ids are UUIDs so existing Supabase data can be imported with the same ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email')->unique();
            $table->string('display_name', 80);
            $table->string('avatar_url')->nullable();
            // super_admin > admin > member > viewer (see App\Enums\AppRole)
            $table->string('app_role', 20)->default('member');
            // online | away | busy | dnd (see App\Enums\UserStatus)
            $table->string('status', 10)->default('online');
            $table->boolean('is_active')->default(true);
            // Null until the person accepts their invite by choosing a password.
            $table->string('password')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('last_sign_in_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('invite_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('invite_tokens');
        Schema::dropIfExists('sessions');
    }
};
