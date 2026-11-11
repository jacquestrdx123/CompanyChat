<?php

namespace App\Models;

use App\Enums\AppRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A person: their login and their profile (Supabase's auth.users + public.profiles).
 *
 * @property string $id
 * @property string $email
 * @property string $display_name
 * @property AppRole $app_role
 * @property UserStatus $status
 * @property bool $is_active
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    protected $fillable = ['email', 'display_name', 'avatar_url', 'status', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected $attributes = [
        'app_role' => 'member',
        'status' => 'online',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'app_role' => AppRole::class,
            'status' => UserStatus::class,
            'is_active' => 'boolean',
            'joined_at' => 'datetime',
            'last_sign_in_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->app_role->isAdmin();
    }

    public function isViewer(): bool
    {
        return $this->app_role === AppRole::Viewer;
    }

    /** Has accepted the invite (chose a password or signed in at least once). */
    public function hasJoined(): bool
    {
        return $this->joined_at !== null || $this->last_sign_in_at !== null;
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'client_members')->withPivot('role')->withTimestamps();
    }

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'channel_members')->withPivot('role')->withTimestamps();
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    /** Broadcast channel name for things only this person should hear about. */
    public function receivesBroadcastNotificationsOn(): string
    {
        return 'user.'.$this->id;
    }
}
