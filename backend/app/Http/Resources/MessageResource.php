<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Message */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel_id' => $this->channel_id,
            'user_id' => $this->user_id,
            'body' => $this->body,
            // UTC with microseconds and a Z, like the browser's toISOString(), so the app can sort by string
            'created_at' => $this->created_at?->copy()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'edited_at' => $this->edited_at?->toISOString(),
            // same key the Supabase select used, so the frontend types barely change
            'message_attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
        ];
    }
}
