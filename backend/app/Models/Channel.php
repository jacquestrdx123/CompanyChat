<?php

namespace App\Models;

use App\Enums\ChannelKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * A project channel under a client, or a direct message.
 *
 * @property string $id
 * @property ChannelKind $kind
 * @property ?string $client_id
 * @property ?string $name
 * @property bool $is_private
 */
class Channel extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['kind', 'client_id', 'name', 'is_private', 'created_by'];

    protected function casts(): array
    {
        return [
            'kind' => ChannelKind::class,
            'is_private' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'channel_members')->withPivot('role')->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function isProject(): bool
    {
        return $this->kind === ChannelKind::Project;
    }

    public function isDm(): bool
    {
        return $this->kind === ChannelKind::Dm;
    }

    /**
     * Channels the given user may open. The single definition of channel access
     * (Supabase's can_user_access_channel): the channel and its client are not archived, and the user is
     * a channel member, or (for projects) an admin, or a member of the client when the project is public.
     */
    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        if (! $user->is_active) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereNull('channels.archived_at')
            ->where(fn (Builder $q) => $q
                ->whereNull('channels.client_id')
                ->orWhereExists(fn (QueryBuilder $s) => $s->selectRaw('1')->from('clients')
                    ->whereColumn('clients.id', 'channels.client_id')->whereNull('clients.archived_at')))
            ->where(function (Builder $q) use ($user) {
                $q->whereExists(fn (QueryBuilder $s) => $s->selectRaw('1')->from('channel_members')
                    ->whereColumn('channel_members.channel_id', 'channels.id')->where('channel_members.user_id', $user->id));

                $q->orWhere(function (Builder $p) use ($user) {
                    $p->where('channels.kind', ChannelKind::Project->value);
                    if (! $user->isAdmin()) {
                        $p->where('channels.is_private', false)
                            ->whereExists(fn (QueryBuilder $s) => $s->selectRaw('1')->from('client_members')
                                ->whereColumn('client_members.client_id', 'channels.client_id')->where('client_members.user_id', $user->id));
                    }
                });
            });
    }
}
