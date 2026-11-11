<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\InviteNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Ported from tests/admin-policy.test.ts and roles.test.ts, plus the auth flows Supabase used to own. */
class AuthAndAdminTest extends TestCase
{
    public function test_login_logout_and_me(): void
    {
        $user = User::factory()->create(['email' => 'pat@example.com']);

        $this->postJson('/api/auth/login', ['email' => 'pat@example.com', 'password' => 'wrong'])->assertStatus(422);
        $token = $this->postJson('/api/auth/login', ['email' => 'PAT@example.com', 'password' => 'password'])
            ->assertOk()->assertJsonPath('user.id', $user->id)->json('token');

        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('email', 'pat@example.com');
        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_invite_accept_and_resend(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();

        $this->actingAs($member)->postJson('/api/admin/users', ['email' => 'new@example.com'])->assertForbidden();
        $this->actingAs($admin)->postJson('/api/admin/users', ['email' => 'New@Example.com', 'display_name' => 'Newbie'])->assertCreated();
        $this->actingAs($admin)->postJson('/api/admin/users', ['email' => 'new@example.com'])->assertStatus(409);

        $invited = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertFalse($invited->hasJoined());
        $this->actingAs($admin)->getJson('/api/admin/users')->assertJsonFragment(['id' => $invited->id, 'pending' => true]);

        $token = null;
        Notification::assertSentTo($invited, InviteNotification::class, function ($n) use (&$token) {
            $token = $n->token;

            return str_contains($n->toMail(User::where('email', 'new@example.com')->first())->actionUrl, '/accept-invite?');
        });

        // a pending person cannot sign in yet
        $this->postJson('/api/auth/login', ['email' => 'new@example.com', 'password' => 'anything'])->assertStatus(422);

        $this->postJson('/api/auth/accept-invite', ['email' => 'new@example.com', 'token' => 'bad', 'password' => 'longenough'])->assertStatus(422);
        $this->postJson('/api/auth/accept-invite', ['email' => 'new@example.com', 'token' => $token, 'password' => 'longenough'])
            ->assertOk()->assertJsonStructure(['token', 'user']);
        $this->assertTrue($invited->fresh()->hasJoined());

        $this->actingAs($admin)->postJson("/api/admin/users/{$invited->id}/resend-invite")->assertStatus(409);
        $this->actingAs($admin)->postJson("/api/admin/users/{$invited->id}/password-reset")->assertOk();
        Notification::assertSentTo($invited, ResetPassword::class);
    }

    public function test_deactivation_rules_and_effect(): void
    {
        $super = User::factory()->superAdmin()->create();
        $admin = User::factory()->admin()->create();
        $admin2 = User::factory()->admin()->create();
        $member = User::factory()->create();
        $token = $member->createToken('t')->plainTextToken;

        $this->actingAs($admin)->postJson("/api/admin/users/{$admin->id}/deactivate")->assertForbidden();
        $this->actingAs($admin)->postJson("/api/admin/users/{$super->id}/deactivate")->assertForbidden();
        $this->actingAs($admin)->postJson("/api/admin/users/{$admin2->id}/deactivate")->assertForbidden();
        $this->actingAs($super)->postJson("/api/admin/users/{$admin2->id}/deactivate")->assertOk();

        $this->actingAs($admin)->postJson("/api/admin/users/{$member->id}/deactivate")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized(); // tokens revoked
        $this->postJson('/api/auth/login', ['email' => $member->email, 'password' => 'password'])->assertStatus(422);

        $this->actingAs($admin)->postJson("/api/admin/users/{$member->id}/reactivate")->assertOk();
        $this->assertTrue($member->fresh()->is_active);
    }

    public function test_role_changes(): void
    {
        $super = User::factory()->superAdmin()->create();
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();

        $this->actingAs($member)->putJson("/api/admin/users/{$admin->id}/role", ['role' => 'member'])->assertForbidden();
        $this->actingAs($admin)->putJson("/api/admin/users/{$member->id}/role", ['role' => 'viewer'])->assertOk();
        $this->actingAs($admin)->putJson("/api/admin/users/{$member->id}/role", ['role' => 'admin'])->assertForbidden();
        $this->actingAs($admin)->putJson("/api/admin/users/{$admin->id}/role", ['role' => 'member'])->assertForbidden();
        $this->actingAs($super)->putJson("/api/admin/users/{$member->id}/role", ['role' => 'admin'])->assertOk();
        $this->actingAs($super)->putJson("/api/admin/users/{$member->id}/role", ['role' => 'super_admin'])->assertForbidden();
        $this->assertSame('admin', $member->fresh()->app_role->value);
    }

    public function test_profile_update_cannot_touch_role(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->patchJson('/api/me', ['status' => 'dnd', 'display_name' => ' Pat ', 'app_role' => 'admin'])
            ->assertOk()->assertJsonPath('status', 'dnd')->assertJsonPath('display_name', 'Pat')->assertJsonPath('app_role', 'member');
        $this->actingAs($user)->patchJson('/api/me', ['status' => 'sleeping'])->assertStatus(422);
    }

    public function test_directory_hides_emails_from_non_admins(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create();

        $this->actingAs(User::factory()->create())->getJson('/api/users')->assertOk()->assertJsonMissingPath('0.email');
        $this->actingAs($admin)->getJson('/api/users')->assertJsonStructure([['email']]);
    }
}
