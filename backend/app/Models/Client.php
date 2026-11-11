<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A client (level 1). Projects (channels of kind "project") live under it.
 *
 * @property string $id
 * @property string $name
 */
class Client extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['name', 'created_by'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'client_members')->withPivot('role')->withTimestamps();
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
