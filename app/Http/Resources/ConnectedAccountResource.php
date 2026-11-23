<?php

namespace App\Http\Resources;

use App\Models\ConnectedAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ConnectedAccount
 */
class ConnectedAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'account_type' => $this->account_type,
            'provider_account_id' => $this->provider_account_id,
            'display_name' => $this->display_name,
            'email' => $this->email,
            'picture_url' => $this->picture_url,
            'scopes' => $this->scopes ?? [],
            'status' => $this->status,
            'token_expires_at' => $this->token_expires_at?->toISOString(),
            'last_synced_at' => $this->last_synced_at?->toISOString(),
            'connected_at' => $this->created_at?->toISOString(),
        ];
    }
}
