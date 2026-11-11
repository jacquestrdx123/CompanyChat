<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file on a message. Stored on the "attachments" disk at <channel_id>/<message_id>/<n>_<file name>.
 * Deleting a single attachment removes the file and sets deleted_at, so the chat can show a tag in its place.
 */
class MessageAttachment extends Model
{
    use HasUuids;

    public const MAX_BYTES = 10 * 1024 * 1024;

    protected $fillable = ['message_id', 'channel_id', 'path', 'file_name', 'mime_type', 'size_bytes'];

    protected $hidden = ['created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
