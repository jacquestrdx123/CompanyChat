<?php

namespace App\Http\Resources;

use App\Models\Channel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Channel */
class ChannelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'client_id' => $this->client_id,
            'name' => $this->name,
            'is_private' => $this->is_private,
            'archived_at' => $this->archived_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'member_ids' => $this->whenLoaded('members', fn () => $this->members->pluck('id')->values()),
        ];
    }
}
