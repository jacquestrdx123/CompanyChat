<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'id' => $this->id,
            'display_name' => $this->display_name,
            'avatar_url' => $this->avatar_url,
            'status' => $this->status->value,
            'app_role' => $this->app_role->value,
            'is_active' => $this->is_active,
            // Everyone can see the office directory; email addresses only to admins and yourself.
            'email' => $this->when($viewer && ($viewer->isAdmin() || $viewer->id === $this->id), $this->email),
        ];
    }
}
